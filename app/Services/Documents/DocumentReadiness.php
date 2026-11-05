<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\SignatureMode;
use App\Models\ApplicantDocument;

/**
 * Works out what is still missing before a document can be submitted.
 */
class DocumentReadiness
{
    public function __construct(private readonly FormService $forms) {}

    /**
     * @return array{form: bool, acknowledgement: bool, upload: bool, supporting: list<string>}
     */
    public function missing(ApplicantDocument $document): array
    {
        $supportingDone = $document->currentSupportingFiles()->pluck('requirement_key')->all();
        $missingSupporting = [];

        foreach ($document->template->supportingRequirements() as $req) {
            if (($req['required'] ?? false) && ! in_array($req['key'], $supportingDone, true)) {
                $missingSupporting[] = $req['key'];
            }
        }

        return [
            'form' => $document->hasForm() && ! $this->forms->isComplete($document),
            'acknowledgement' => $document->isAcknowledgement() && $document->acknowledged_at === null,
            'upload' => $document->needsMainUpload() && $document->current_version_id === null,
            'supporting' => $missingSupporting,
        ];
    }

    public function isReady(ApplicantDocument $document): bool
    {
        $m = $this->missing($document);

        return ! $m['form'] && ! $m['acknowledgement'] && ! $m['upload'] && $m['supporting'] === [];
    }

    /**
     * Status to show while the applicant is still working on the document.
     */
    public function draftStatus(ApplicantDocument $document): DocumentStatus
    {
        $m = $this->missing($document);

        if ($m['form'] || $m['acknowledgement']) {
            return DocumentStatus::InProgress;
        }
        if ($m['upload'] && $document->signature_mode_snapshot === SignatureMode::WetSignatureUpload) {
            return DocumentStatus::AwaitingWetSignatureUpload;
        }
        if ($m['upload']) {
            return DocumentStatus::InProgress;
        }
        if ($m['supporting'] !== []) {
            return DocumentStatus::AwaitingSupportingFiles;
        }

        return DocumentStatus::InProgress;
    }
}
