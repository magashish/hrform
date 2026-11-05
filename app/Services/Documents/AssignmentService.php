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

    public function assign(Applicant $applicant, DocumentTemplate $template, User $actor, ?bool $required = null): ApplicantDocument
    {
        return DB::transaction(function () use ($applicant, $template, $actor, $required) {
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
            ]);
            $document->status = DocumentStatus::NotStarted;
            $document->save();

            $this->audit->record('document.assigned', $document, [
                'template' => $template->code,
                'required' => $document->required,
            ], hrActor: $actor, applicantRef: $applicant->applicant_ref);

            $this->progress->refresh($applicant);

            return $document;
        });
    }

    /**
     * @param  iterable<DocumentTemplate>  $templates
     */
    public function assignMany(Applicant $applicant, iterable $templates, User $actor): void
    {
        foreach ($templates as $template) {
            $this->assign($applicant, $template, $actor);
        }
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
