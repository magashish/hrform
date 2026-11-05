<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Events\CorrectionRequested;
use App\Models\ApplicantDocument;
use App\Models\DocumentVersion;
use App\Models\SupportingFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFileStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HR review actions on applicant documents.
 */
class ReviewService
{
    public function __construct(
        private readonly DocumentStateMachine $states,
        private readonly ApplicantProgressService $progress,
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    public function markUnderReview(ApplicantDocument $document, User $reviewer): void
    {
        if ($document->status !== DocumentStatus::Submitted) {
            return;
        }

        $this->states->transition($document, DocumentStatus::UnderReview);
    }

    public function approve(ApplicantDocument $document, User $reviewer): void
    {
        DB::transaction(function () use ($document, $reviewer) {
            $this->states->transition($document, DocumentStatus::Approved);
            $document->reviewed_at = now();
            $document->reviewer_id = $reviewer->id;
            $document->save();

            $this->audit->record('document.approved', $document, ['template' => $document->template->code],
                hrActor: $reviewer, applicantRef: $document->applicant->applicant_ref);

            $this->progress->refresh($document->applicant);
        });
    }

    public function requestCorrection(ApplicantDocument $document, User $reviewer, string $message, string $scope = 'document'): void
    {
        DB::transaction(function () use ($document, $reviewer, $message, $scope) {
            $this->states->transition($document, DocumentStatus::CorrectionRequired);
            $document->reviewed_at = now();
            $document->reviewer_id = $reviewer->id;
            $document->save();

            $document->corrections()->create([
                'requested_by' => $reviewer->id,
                'scope' => $scope,
                'message' => $message,
            ]);

            // The message itself is not audited (it may contain personal details).
            $this->audit->record('document.correction_requested', $document, [
                'template' => $document->template->code,
                'scope' => $scope,
            ], hrActor: $reviewer, applicantRef: $document->applicant->applicant_ref);

            $this->progress->refresh($document->applicant);
        });

        CorrectionRequested::dispatch($document);
    }

    /**
     * Deletes one stored file (binary + record) to reduce exposure.
     */
    public function deleteFile(DocumentVersion|SupportingFile $file, User $actor): void
    {
        $document = $file->document;

        if (! $this->files->delete($file->disk, $file->storage_path)) {
            throw new RuntimeException('The file could not be deleted from storage.');
        }

        DB::transaction(function () use ($file, $document, $actor) {
            $isCurrentMain = $file instanceof DocumentVersion && $document->current_version_id === $file->id;
            $kind = $file instanceof DocumentVersion ? 'document_version' : 'supporting_file';
            $file->delete();

            if ($isCurrentMain) {
                $document->current_version_id = null;
                $document->save();
            }

            $this->audit->record('file.deleted', $document, [
                'template' => $document->template->code,
                'kind' => $kind,
                'was_current' => $isCurrentMain,
            ], hrActor: $actor, applicantRef: $document->applicant->applicant_ref);
        });
    }
}
