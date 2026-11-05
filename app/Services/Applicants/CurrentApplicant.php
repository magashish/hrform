<?php

namespace App\Services\Applicants;

use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use RuntimeException;

/**
 * Request-scoped holder for the signed-in applicant, populated only by the
 * EnsureApplicantSession middleware. All applicant-facing queries start
 * from here, never from IDs supplied in the URL or request body.
 */
class CurrentApplicant
{
    private ?Applicant $applicant = null;

    private ?ApplicantInvitation $invitation = null;

    public function set(Applicant $applicant, ApplicantInvitation $invitation): void
    {
        $this->applicant = $applicant;
        $this->invitation = $invitation;
    }

    public function get(): Applicant
    {
        return $this->applicant ?? throw new RuntimeException('No applicant session.');
    }

    public function invitation(): ApplicantInvitation
    {
        return $this->invitation ?? throw new RuntimeException('No applicant session.');
    }

    public function check(): bool
    {
        return $this->applicant !== null;
    }
}
