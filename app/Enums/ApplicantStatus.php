<?php

namespace App\Enums;

/**
 * Applicant lifecycle. See PROJECT_ANALYSIS.md §2.3. Document-driven
 * transitions (submitted, ready_for_download, ...) arrive in Phases D/E.
 */
enum ApplicantStatus: string
{
    case Invited = 'invited';
    case InProgress = 'in_progress';
    case CorrectionRequired = 'correction_required';
    case Submitted = 'submitted';
    case ReadyForDownload = 'ready_for_download';
    case Transferred = 'transferred';
    case RetentionPending = 'retention_pending';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::InProgress => 'In progress',
            self::CorrectionRequired => 'Correction required',
            self::Submitted => 'Submitted',
            self::ReadyForDownload => 'Ready for download',
            self::Transferred => 'Transferred',
            self::RetentionPending => 'Retention pending',
        };
    }
}
