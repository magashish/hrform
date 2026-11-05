<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Models\ExportArchive;
use App\Services\Audit\AuditLogger;
use App\Services\Retention\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    /**
     * @return array{0: Applicant, 1: string} applicant and stored file path
     */
    private function transferredApplicant(int $daysAgo = 61, array $attributes = []): array
    {
        [$applicant] = $this->applicantWithInvitation($attributes);
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create());
        $this->signInApplicant($applicant);
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf()]);
        $this->flushSession();
        $path = $doc->fresh()->currentVersion->storage_path;

        $applicant->forceFill([
            'transferred_at' => now()->subDays($daysAgo),
            'transferred_by' => $applicant->created_by,
            'status' => 'transferred',
            'deletion_due_at' => now()->subDays($daysAgo)->addDays(60),
        ])->save();

        return [$applicant->fresh(), $path];
    }

    public function test_dry_run_deletes_nothing_and_records_a_report(): void
    {
        [$due, $path] = $this->transferredApplicant(61);
        [$notDue] = $this->transferredApplicant(10);

        $this->artisan('portal:retention:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('applicants', ['id' => $due->id]);
        Storage::disk('documents')->assertExists($path);
        $this->assertDatabaseHas('retention_events', ['applicant_ref' => $due->applicant_ref, 'action' => 'dry_run']);
        $this->assertDatabaseMissing('retention_events', ['applicant_ref' => $notDue->applicant_ref]);
    }

    public function test_scheduled_run_is_dry_unless_auto_purge_is_enabled(): void
    {
        [$due] = $this->transferredApplicant(61);

        config(['portal.retention.auto_purge' => false]);
        $this->artisan('portal:retention:purge')->assertSuccessful();
        $this->assertDatabaseHas('applicants', ['id' => $due->id]);

        config(['portal.retention.auto_purge' => true]);
        $this->artisan('portal:retention:purge')->assertSuccessful();
        $this->assertDatabaseMissing('applicants', ['id' => $due->id]);
    }

    public function test_purge_removes_files_and_personal_data_but_keeps_pseudonymous_evidence(): void
    {
        [$due, $path] = $this->transferredApplicant(61, ['first_name' => 'Purge', 'last_name' => 'Me', 'email' => 'purge@example.com']);
        $ref = $due->applicant_ref;
        Storage::disk('exports')->put('zips/old.zip', 'zip');
        ExportArchive::create(['applicant_id' => $due->id, 'requested_by' => $due->created_by, 'status' => 'ready', 'disk' => 'exports', 'storage_path' => 'zips/old.zip']);

        $this->artisan('portal:retention:purge', ['--force' => true])->assertSuccessful();

        Storage::disk('documents')->assertMissing($path);
        Storage::disk('exports')->assertMissing('zips/old.zip');
        foreach (['applicants', 'applicant_invitations', 'applicant_documents', 'document_versions', 'applicant_consents', 'export_archives'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertDatabaseHas('retention_events', ['applicant_ref' => $ref, 'action' => 'purged', 'result' => 'ok']);
        $this->assertTrue(AuditEvent::where('applicant_ref', $ref)->where('event', 'retention.purged')->exists());

        // No personal data left anywhere in the database.
        foreach (['audit_events', 'retention_events', 'notification_deliveries'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('Purge', $dump);
            $this->assertStringNotContainsString('purge@example.com', $dump);
        }
    }

    public function test_legal_hold_blocks_purge(): void
    {
        [$held, $path] = $this->transferredApplicant(61);
        $held->forceFill(['legal_hold' => true])->save();

        $this->artisan('portal:retention:purge', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('applicants', ['id' => $held->id]);
        Storage::disk('documents')->assertExists($path);

        $this->expectException(\RuntimeException::class);
        app(RetentionService::class)->purge($held, 'manual');
    }

    public function test_incomplete_applicants_are_not_purged_by_default(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        $applicant->invitations()->update(['expires_at' => now()->subDays(100)]);
        $this->travel(200)->days();

        $this->artisan('portal:retention:purge', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('applicants', ['id' => $applicant->id]);
    }

    public function test_hr_manual_delete_requires_typed_confirmation_and_respects_legal_hold(): void
    {
        [$applicant, $path] = $this->transferredApplicant(5);
        $this->actingAsHr();

        $this->delete("/hr/applicants/{$applicant->public_id}", ['confirmation' => 'delete'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('applicants', ['id' => $applicant->id]);

        $applicant->forceFill(['legal_hold' => true])->save();
        $this->delete("/hr/applicants/{$applicant->public_id}", ['confirmation' => 'DELETE'])->assertForbidden();

        $applicant->forceFill(['legal_hold' => false])->save();
        $this->delete("/hr/applicants/{$applicant->public_id}", ['confirmation' => 'DELETE'])->assertRedirect('/hr/applicants');

        $this->assertDatabaseMissing('applicants', ['id' => $applicant->id]);
        Storage::disk('documents')->assertMissing($path);
        $this->assertDatabaseHas('retention_events', ['applicant_ref' => $applicant->applicant_ref, 'trigger' => 'manual']);
    }

    public function test_legal_hold_can_be_set_by_hr_with_reason(): void
    {
        $this->actingAsHr();
        [$applicant] = $this->applicantWithInvitation();

        $this->post("/hr/applicants/{$applicant->public_id}/legal-hold", ['hold' => '1'])->assertSessionHasErrors('reason');
        $this->post("/hr/applicants/{$applicant->public_id}/legal-hold", ['hold' => '1', 'reason' => 'Pending claim'])->assertRedirect();

        $this->assertTrue($applicant->fresh()->legal_hold);
        $this->assertDatabaseHas('audit_events', ['event' => 'applicant.legal_hold_set']);
    }

    public function test_expired_exports_are_cleaned_up(): void
    {
        [$applicant] = $this->applicantWithInvitation();
        Storage::disk('exports')->put('zips/a.zip', 'zip');
        Storage::disk('exports')->put('zips/b.zip', 'zip');
        $old = ExportArchive::create(['applicant_id' => $applicant->id, 'requested_by' => $applicant->created_by, 'status' => 'ready', 'disk' => 'exports', 'storage_path' => 'zips/a.zip', 'expires_at' => now()->subMinute()]);
        $fresh = ExportArchive::create(['applicant_id' => $applicant->id, 'requested_by' => $applicant->created_by, 'status' => 'ready', 'disk' => 'exports', 'storage_path' => 'zips/b.zip', 'expires_at' => now()->addHour()]);

        $this->artisan('portal:exports:cleanup')->assertSuccessful();

        Storage::disk('exports')->assertMissing('zips/a.zip');
        Storage::disk('exports')->assertExists('zips/b.zip');
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('ready', $fresh->fresh()->status);
    }

    public function test_audit_prune_removes_only_old_events(): void
    {
        config(['portal.audit.retention_days' => 30]);
        app(AuditLogger::class)->record('auth.lockout');
        $this->travel(31)->days();
        app(AuditLogger::class)->record('auth.lockout');

        $this->artisan('portal:audit:prune')->assertSuccessful();

        $this->assertSame(1, AuditEvent::where('event', 'auth.lockout')->count());
        $this->assertTrue(AuditEvent::where('event', 'audit.pruned')->exists());
    }
}
