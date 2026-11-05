<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case AwaitingWetSignatureUpload = 'awaiting_wet_signature_upload';
    case AwaitingSupportingFiles = 'awaiting_supporting_files';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case CorrectionRequired = 'correction_required';
    case Approved = 'approved';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::AwaitingWetSignatureUpload => 'Awaiting signed upload',
            self::AwaitingSupportingFiles => 'Awaiting supporting files',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::CorrectionRequired => 'Correction required',
            self::Approved => 'Approved',
            self::Complete => 'Complete',
        };
    }

    /** Statuses in which the applicant may still edit the document. */
    public function isEditableByApplicant(): bool
    {
        return in_array($this, [
            self::NotStarted, self::InProgress, self::AwaitingWetSignatureUpload,
            self::AwaitingSupportingFiles, self::CorrectionRequired,
        ], true);
    }

    /** Submitted or later in the review pipeline. */
    public function isSubmittedOrLater(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Approved, self::Complete], true);
    }

    public function isApproved(): bool
    {
        return in_array($this, [self::Approved, self::Complete], true);
    }
}
