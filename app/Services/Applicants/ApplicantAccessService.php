<?php

namespace App\Services\Applicants;

use App\Mail\ApplicantOtpMail;
use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use App\Models\ApplicantOtp;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Applicant sign-in: invitation code -> email confirmation -> email OTP ->
 * session bound to applicant + invitation.
 *
 * Every failure produces the same generic message for the user; the
 * specific reason is recorded only in the audit log.
 */
class ApplicantAccessService
{
    public const SESSION_PENDING = 'portal.pending_invitation_id';

    public const SESSION_OTP_REQUESTED = 'portal.otp_requested';

    public const SESSION_APPLICANT = 'portal.applicant_id';

    public const SESSION_INVITATION = 'portal.invitation_id';

    public const SESSION_STARTED = 'portal.started_at';

    public const SESSION_LAST_ACTIVITY = 'portal.last_activity';

    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1. Returns the usable invitation, or null (generic failure).
     */
    public function verifyCode(Request $request, string $code): ?ApplicantInvitation
    {
        $invitation = $this->invitations->findByCode($code);

        if (! $invitation) {
            $this->audit->record('applicant.access_failed', metadata: ['reason' => 'unknown_code'], actorType: AuditEvent::ACTOR_SYSTEM);

            return null;
        }

        if (! $invitation->isUsable()) {
            $invitation->increment('failed_attempts');
            $this->auditApplicant('applicant.access_failed', $invitation->applicant, $invitation, [
                'reason' => $invitation->isRevoked() ? 'revoked_code' : 'expired_code',
            ]);

            return null;
        }

        $this->forget($request);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_PENDING, $invitation->id);

        return $invitation;
    }

    public function pendingInvitation(Request $request): ?ApplicantInvitation
    {
        $id = $request->session()->get(self::SESSION_PENDING);
        $invitation = $id ? ApplicantInvitation::with('applicant')->find($id) : null;

        return $invitation && $invitation->isUsable() ? $invitation : null;
    }

    /**
     * Step 2. Sends an OTP only if the typed email matches the email on
     * file. The caller always shows the same response.
     */
    public function requestOtp(Request $request, ApplicantInvitation $invitation, string $email): void
    {
        $request->session()->put(self::SESSION_OTP_REQUESTED, true);
        $applicant = $invitation->applicant;

        if (! hash_equals(mb_strtolower(trim($applicant->email)), mb_strtolower(trim($email)))) {
            $invitation->increment('failed_attempts');
            $this->auditApplicant('applicant.otp_failed', $applicant, $invitation, ['reason' => 'email_mismatch']);

            return;
        }

        $window = (int) config('portal.applicant_access.otp_send_window_minutes', 15);
        $recentSends = ApplicantOtp::where('invitation_id', $invitation->id)
            ->where('created_at', '>', now()->subMinutes($window))
            ->count();

        if ($recentSends >= (int) config('portal.applicant_access.otp_max_sends_per_window', 3)) {
            $this->auditApplicant('applicant.otp_failed', $applicant, $invitation, ['reason' => 'send_limit']);

            return;
        }

        // Only the latest code is valid.
        ApplicantOtp::where('invitation_id', $invitation->id)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        ApplicantOtp::create([
            'applicant_id' => $applicant->id,
            'invitation_id' => $invitation->id,
            'code_hash' => $this->hashOtp($code, $invitation),
            'expires_at' => now()->addMinutes((int) config('portal.applicant_access.otp_ttl_minutes', 10)),
        ]);

        Mail::to($applicant->email)->queue(new ApplicantOtpMail($applicant, $code));

        $this->auditApplicant('applicant.otp_sent', $applicant, $invitation);
    }

    /**
     * Step 3. On success establishes the applicant session.
     */
    public function verifyOtp(Request $request, ApplicantInvitation $invitation, string $code): bool
    {
        $applicant = $invitation->applicant;
        $otp = ApplicantOtp::where('invitation_id', $invitation->id)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        $reason = match (true) {
            $otp === null => 'no_active_code',
            $otp->expires_at->isPast() => 'expired',
            $otp->attempts >= (int) config('portal.applicant_access.otp_max_attempts', 5) => 'too_many_attempts',
            default => null,
        };

        if ($reason === null && ! hash_equals($otp->code_hash, $this->hashOtp(preg_replace('/\D/', '', $code) ?? '', $invitation))) {
            $otp->increment('attempts');
            $reason = 'wrong_code';
        }

        if ($reason !== null) {
            $this->auditApplicant('applicant.otp_failed', $applicant, $invitation, ['reason' => $reason]);

            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        $invitation->forceFill([
            'first_used_at' => $invitation->first_used_at ?? now(),
            'last_used_at' => now(),
        ])->save();

        $session = $request->session();
        $this->forget($request);
        $session->regenerate();
        $session->put([
            self::SESSION_APPLICANT => $applicant->id,
            self::SESSION_INVITATION => $invitation->id,
            self::SESSION_STARTED => now()->getTimestamp(),
            self::SESSION_LAST_ACTIVITY => now()->getTimestamp(),
        ]);

        $this->auditApplicant('applicant.signed_in', $applicant, $invitation);

        return true;
    }

    public function signOut(Request $request, ?Applicant $applicant = null, string $reason = 'user'): void
    {
        if ($applicant) {
            $this->audit->record(
                $reason === 'user' ? 'applicant.signed_out' : 'applicant.session_ended',
                $applicant,
                $reason === 'user' ? [] : ['reason' => $reason],
                applicantRef: $applicant->applicant_ref,
                actorType: AuditEvent::ACTOR_APPLICANT,
                actorId: $applicant->applicant_ref,
            );
        }

        $this->forget($request);
        $request->session()->regenerate(true);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_PENDING, self::SESSION_OTP_REQUESTED, self::SESSION_APPLICANT,
            self::SESSION_INVITATION, self::SESSION_STARTED, self::SESSION_LAST_ACTIVITY,
        ]);
    }

    private function hashOtp(string $code, ApplicantInvitation $invitation): string
    {
        return hash_hmac('sha256', $invitation->id.'|'.$code, (string) config('app.key'));
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function auditApplicant(string $event, Applicant $applicant, ApplicantInvitation $invitation, array $metadata = []): void
    {
        $this->audit->record($event, $invitation, $metadata,
            applicantRef: $applicant->applicant_ref,
            actorType: AuditEvent::ACTOR_APPLICANT,
            actorId: $applicant->applicant_ref,
        );
    }
}
