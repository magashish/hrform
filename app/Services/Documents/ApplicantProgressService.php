<?php

namespace App\Services\Documents;

use App\Enums\ApplicantStatus;
use App\Enums\DocumentStatus;
use App\Events\PacketCompleted;
use App\Models\Applicant;

/**
 * Derives the applicant's status from their assigned required documents.
 * Lifecycle states set by HR (transferred, retention pending) are kept.
 */
class ApplicantProgressService
{
    public function refresh(Applicant $applicant): void
    {
        if (in_array($applicant->status, [ApplicantStatus::Transferred, ApplicantStatus::RetentionPending], true)) {
            return;
        }

        $documents = $applicant->documents()->get(['id', 'status', 'required']);
        $required = $documents->where('required', true);
        $wasComplete = $applicant->completed_at !== null;

        $status = match (true) {
            $applicant->consented_at === null => ApplicantStatus::Invited,
            $documents->contains(fn ($d) => $d->status === DocumentStatus::CorrectionRequired) => ApplicantStatus::CorrectionRequired,
            $required->isNotEmpty() && $required->every(fn ($d) => $d->status->isApproved()) => ApplicantStatus::ReadyForDownload,
            $required->isNotEmpty() && $required->every(fn ($d) => $d->status->isSubmittedOrLater()) => ApplicantStatus::Submitted,
            default => ApplicantStatus::InProgress,
        };

        $allSubmitted = $required->isNotEmpty() && $required->every(fn ($d) => $d->status->isSubmittedOrLater());

        $applicant->status = $status;
        if ($allSubmitted && ! $wasComplete) {
            $applicant->completed_at = now();
        }
        $applicant->save();

        if ($allSubmitted && ! $wasComplete) {
            PacketCompleted::dispatch($applicant);
        }
    }

    /**
     * @return array{total: int, done: int, percent: int}
     */
    public function summary(Applicant $applicant): array
    {
        $required = $applicant->documents()->where('required', true)->get(['id', 'status']);
        $done = $required->filter(fn ($d) => $d->status->isSubmittedOrLater())->count();
        $total = $required->count();

        return ['total' => $total, 'done' => $done, 'percent' => $total ? (int) round($done / $total * 100) : 0];
    }
}
