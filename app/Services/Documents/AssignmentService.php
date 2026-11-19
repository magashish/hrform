<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

class AssignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApplicantProgressService $progress,
    ) {}

    /**
     * @param  string|null  $stage  defaults to the template's default stage
     * @param  bool|null  $release  make it visible now; defaults to true only for the initial stage
     */
    public function assign(Applicant $applicant, DocumentTemplate $template, User $actor, ?bool $required = null, ?string $stage = null, ?bool $release = null): ApplicantDocument
    {
        $stage = $stage !== null && array_key_exists($stage, (array) config('portal.stages')) ? $stage : $template->default_stage;
        $release ??= $stage === 'initial';

        return DB::transaction(function () use ($applicant, $template, $actor, $required, $stage, $release) {
            $existing = $applicant->documents()->where('document_template_id', $template->id)->first();
            if ($existing) {
                return $existing;
            }

            $document = new ApplicantDocument([
                'applicant_id' => $applicant->id,
                'document_template_id' => $template->id,
                'required' => $required ?? $template->required_by_default,
                'input_mode_snapshot' => $template->input_mode,
                'signature_mode_snapshot' => $template->signature_mode,
                'assigned_at' => now(),
                'stage' => $stage,
                'released_at' => $release ? now() : null,
            ]);
            $document->status = DocumentStatus::NotStarted;
            $document->save();

            $this->audit->record('document.assigned', $document, [
                'template' => $template->code,
                'required' => $document->required,
                'stage' => $stage,
                'released' => $release,
            ], hrActor: $actor, applicantRef: $applicant->applicant_ref);

            $this->progress->refresh($applicant);

            return $document;
        });
    }

    /**
     * @param  iterable<DocumentTemplate>  $templates
     * @param  array<int, string>  $stages  template id => stage
     */
    public function assignMany(Applicant $applicant, iterable $templates, User $actor, array $stages = []): void
    {
        foreach ($templates as $template) {
            $this->assign($applicant, $template, $actor, stage: $stages[$template->id] ?? null);
        }
    }

    /**
     * Makes every held document in a stage visible to the applicant.
     *
     * @return int number of documents released
     */
    public function releaseStage(Applicant $applicant, string $stage, User $actor): int
    {
        return DB::transaction(function () use ($applicant, $stage, $actor) {
            $count = $applicant->documents()->where('stage', $stage)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            if ($count > 0) {
                $this->audit->record('documents.released', $applicant, ['stage' => $stage, 'count' => $count],
                    hrActor: $actor, applicantRef: $applicant->applicant_ref);
                $this->progress->refresh($applicant);
            }

            return $count;
        });
    }

    /**
     * Only untouched documents can be unassigned (no data or files lost).
     */
    public function unassign(ApplicantDocument $document, User $actor): void
    {
        if ($document->status !== DocumentStatus::NotStarted || $document->versions()->exists() || $document->supportingFiles()->exists()) {
            throw new LogicException('Only documents the applicant has not started can be removed.');
        }

        DB::transaction(function () use ($document, $actor) {
            $applicant = $document->applicant;
            $code = $document->template->code;
            $document->delete();

            $this->audit->record('document.unassigned', metadata: ['template' => $code],
                hrActor: $actor, applicantRef: $applicant->applicant_ref);

            $this->progress->refresh($applicant);
        });
    }
}
