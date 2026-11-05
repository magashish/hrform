<?php

namespace App\Services\Retention;

use App\Enums\ApplicantStatus;
use App\Enums\DocumentStatus;
use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\ExportArchive;
use App\Models\User;
use App\Notifications\HrAlert;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentStateMachine;
use App\Services\Files\PrivateFileStore;
use App\Services\Notifications\HrNotifier;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Retention and purge of applicant packets.
 *
 * Purge order: delete every file binary (documents, supporting files,
 * exports) and verify -> delete the applicant row (cascades all personal
 * data) -> record a pseudonymous retention_events row. A legal hold blocks
 * every purge path. Backups are NOT affected: see docs/retention-and-backup.md.
 */
class RetentionService
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly HrNotifier $notifier,
    ) {}

    /**
     * Applicants whose retention period has ended.
     *
     * @return Builder<Applicant>
     */
    public function dueQuery(): Builder
    {
        $query = Applicant::query()->where('legal_hold', false)->where(function (Builder $q) {
            $q->where(fn ($t) => $t->whereNotNull('transferred_at')->whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', now()));

            $abandonedDays = (int) config('portal.retention.abandoned_days', 0);
            if ($abandonedDays > 0) {
                $q->orWhere(fn ($a) => $a->whereNull('transferred_at')->where('updated_at', '<=', now()->subDays($abandonedDays)));
            }
        });

        return $query;
    }

    /**
     * @return array{due: int, held: int, applicants: Collection<int, Applicant>}
     */
    public function report(): array
    {
        $due = $this->dueQuery()->get();
        $held = Applicant::where('legal_hold', true)->whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', now())->count();

        return ['due' => $due->count(), 'held' => $held, 'applicants' => $due];
    }

    /**
     * @return array{purged: int, failed: int, dry_run: bool}
     */
    public function run(bool $dryRun, string $trigger = 'scheduled'): array
    {
        $report = $this->report();

        if ($dryRun) {
            foreach ($report['applicants'] as $applicant) {
                DB::table('retention_events')->insert([
                    'applicant_ref' => $applicant->applicant_ref,
                    'action' => 'dry_run',
                    'trigger' => $trigger,
                    'due_at' => $applicant->deletion_due_at,
                    'result' => 'would_purge',
                    'created_at' => now(),
                ]);
            }

            return ['purged' => 0, 'failed' => 0, 'dry_run' => true];
        }

        $purged = 0;
        $failed = 0;
        foreach ($report['applicants'] as $applicant) {
            try {
                $this->purge($applicant, $trigger);
                $purged++;
            } catch (Throwable $e) {
                $failed++;
                DB::table('retention_events')->insert([
                    'applicant_ref' => $applicant->applicant_ref,
                    'action' => 'failed',
                    'trigger' => $trigger,
                    'due_at' => $applicant->deletion_due_at,
                    'result' => class_basename($e),
                    'created_at' => now(),
                ]);
                $this->audit->record('retention.purge_failed', metadata: ['trigger' => $trigger, 'error' => class_basename($e)],
                    applicantRef: $applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);
                $this->notifier->notify('purge_failed', new HrAlert(
                    'Automatic deletion failed',
                    'The scheduled deletion of an applicant packet failed and will be retried. Please check the System page.',
                    route('hr.system'),
                ));
            }
        }

        return ['purged' => $purged, 'failed' => $failed, 'dry_run' => false];
    }

    /**
     * Permanently deletes an applicant's packet and personal data.
     */
    public function purge(Applicant $applicant, string $trigger, ?User $actor = null): void
    {
        $applicant->refresh();
        if ($applicant->legal_hold) {
            $this->audit->record('retention.skipped_legal_hold', applicantRef: $applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);
            throw new RuntimeException('Applicant is under legal hold.');
        }

        $ref = $applicant->applicant_ref;
        $due = $applicant->deletion_due_at;
        $deleted = 0;

        // 1. Binaries first. Abort on any failure so nothing is orphaned.
        foreach ($applicant->documents()->with(['versions', 'supportingFiles'])->get() as $document) {
            foreach ([...$document->versions, ...$document->supportingFiles] as $file) {
                if (! $this->files->delete($file->disk, $file->storage_path)) {
                    throw new RuntimeException('File deletion could not be verified.');
                }
                $deleted++;
            }
        }
        foreach (ExportArchive::where('applicant_id', $applicant->id)->whereNotNull('storage_path')->get() as $export) {
            if (! $this->files->delete((string) $export->disk, (string) $export->storage_path)) {
                throw new RuntimeException('Export deletion could not be verified.');
            }
        }

        // 2. Rows (cascade removes invitations, OTPs, consents, documents, versions, exports...).
        DB::transaction(function () use ($applicant, $ref, $due, $trigger, $deleted, $actor) {
            $applicant->delete();

            DB::table('retention_events')->insert([
                'applicant_ref' => $ref,
                'action' => 'purged',
                'trigger' => $trigger,
                'due_at' => $due,
                'completed_at' => now(),
                'result' => 'ok',
                'metadata' => json_encode(['files_deleted' => $deleted]),
                'created_at' => now(),
            ]);

            $this->audit->record('retention.purged', metadata: ['trigger' => $trigger, 'files_deleted' => $deleted],
                hrActor: $actor, applicantRef: $ref,
                actorType: $actor ? AuditEvent::ACTOR_HR : AuditEvent::ACTOR_SYSTEM,
                actorId: $actor ? (string) $actor->id : null);
        });
    }

    public function markTransferred(Applicant $applicant, User $actor): void
    {
        DB::transaction(function () use ($applicant, $actor) {
            $days = (int) $this->settings->get('retention_days');

            $applicant->forceFill([
                'transferred_at' => now(),
                'transferred_by' => $actor->id,
                'status' => ApplicantStatus::Transferred,
                'deletion_due_at' => now()->addDays($days),
            ])->save();

            foreach ($applicant->documents as $document) {
                if ($document->status->isApproved()) {
                    app(DocumentStateMachine::class)->transition($document, DocumentStatus::Complete);
                }
            }

            // Access is no longer needed once the packet has been transferred.
            $applicant->invitations()->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'transferred']);

            $this->audit->record('applicant.transferred', $applicant, ['retention_days' => $days],
                hrActor: $actor, applicantRef: $applicant->applicant_ref);
        });
    }

    public function setLegalHold(Applicant $applicant, bool $hold, ?string $reason, User $actor): void
    {
        $applicant->forceFill(['legal_hold' => $hold, 'legal_hold_reason' => $hold ? $reason : null])->save();

        $this->audit->record($hold ? 'applicant.legal_hold_set' : 'applicant.legal_hold_cleared', $applicant,
            hrActor: $actor, applicantRef: $applicant->applicant_ref);
    }
}
