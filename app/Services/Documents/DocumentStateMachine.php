<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus as S;
use App\Models\ApplicantDocument;
use LogicException;

/**
 * The only place document statuses change. Illegal transitions throw.
 */
class DocumentStateMachine
{
    /** @var array<string, list<S>> */
    private array $map;

    public function __construct()
    {
        $draft = [S::InProgress, S::AwaitingWetSignatureUpload, S::AwaitingSupportingFiles, S::Submitted];

        $this->map = [
            S::NotStarted->value => $draft,
            S::InProgress->value => $draft,
            S::AwaitingWetSignatureUpload->value => $draft,
            S::AwaitingSupportingFiles->value => $draft,
            S::CorrectionRequired->value => [S::Submitted],
            S::Submitted->value => [S::UnderReview, S::Approved, S::CorrectionRequired],
            S::UnderReview->value => [S::Approved, S::CorrectionRequired],
            S::Approved->value => [S::CorrectionRequired, S::Complete],
            S::Complete->value => [],
        ];
    }

    public function can(ApplicantDocument $document, S $to): bool
    {
        return $document->status === $to || in_array($to, $this->map[$document->status->value] ?? [], true);
    }

    public function transition(ApplicantDocument $document, S $to): void
    {
        if ($document->status === $to) {
            return;
        }

        if (! $this->can($document, $to)) {
            throw new LogicException("Illegal document transition {$document->status->value} -> {$to->value}.");
        }

        $document->status = $to;
        $document->save();
    }
}
