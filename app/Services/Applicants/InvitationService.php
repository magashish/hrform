<?php

namespace App\Services\Applicants;

use App\Mail\ApplicantInvitationMail;
use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Invitation lifecycle. Progress lives on the applicant, never on the
 * invitation, so revoking or reissuing never loses applicant work.
 */
class InvitationService
{
    public function __construct(
        private readonly InvitationCode $codes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue a new invitation (revoking any active one) and queue the email.
     * The raw code exists only in memory and in the encrypted queued mail.
     */
    public function issue(Applicant $applicant, User $issuedBy): ApplicantInvitation
    {
        $code = $this->codes->generate();
        $days = (int) config('portal.invitations.expiry_days', 7);

        $invitation = DB::transaction(function () use ($applicant, $issuedBy, $code, $days) {
            $revoked = $this->revokeActive($applicant, $issuedBy, 'reissued', audit: false);

            $invitation = $applicant->invitations()->create([
                'token_hash' => $this->codes->hash($code),
                'issued_at' => now(),
                'expires_at' => now()->addDays($days),
                'created_by' => $issuedBy->id,
            ]);

            $this->audit->record('invitation.issued', $invitation, [
                'reissue' => $revoked > 0 || $applicant->invitations()->count() > 1,
                'expires_in_days' => $days,
            ], hrActor: $issuedBy, applicantRef: $applicant->applicant_ref);

            return $invitation;
        });

        Mail::to($applicant->email)->queue(new ApplicantInvitationMail($applicant, $invitation, $code));

        return $invitation;
    }

    public function revoke(ApplicantInvitation $invitation, User $revokedBy, string $reason = 'revoked_by_hr'): void
    {
        if ($invitation->isRevoked()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();

        $this->audit->record('invitation.revoked', $invitation, ['reason' => $reason],
            hrActor: $revokedBy, applicantRef: $invitation->applicant->applicant_ref);
    }

    /**
     * @return int number of invitations revoked
     */
    public function revokeActive(Applicant $applicant, User $actor, string $reason, bool $audit = true): int
    {
        $active = $applicant->invitations()->whereNull('revoked_at')->where('expires_at', '>', now())->get();

        foreach ($active as $invitation) {
            if ($audit) {
                $this->revoke($invitation, $actor, $reason);
            } else {
                $invitation->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
            }
        }

        return $active->count();
    }

    /**
     * Look up an invitation by raw code. Returns the invitation even when
     * expired/revoked so the caller can audit; check isUsable().
     */
    public function findByCode(string $code): ?ApplicantInvitation
    {
        if (! $this->codes->looksValid($code)) {
            return null;
        }

        return ApplicantInvitation::with('applicant')
            ->where('token_hash', $this->codes->hash($code))
            ->first();
    }
}
