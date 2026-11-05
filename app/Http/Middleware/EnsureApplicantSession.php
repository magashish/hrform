<?php

namespace App\Http\Middleware;

use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use App\Services\Applicants\ApplicantAccessService as Access;
use App\Services\Applicants\CurrentApplicant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every applicant portal page. On each request it re-checks that the
 * session's invitation still belongs to the applicant and is neither
 * revoked nor expired, and enforces idle and absolute timeouts.
 */
class EnsureApplicantSession
{
    public function __construct(
        private readonly Access $access,
        private readonly CurrentApplicant $current,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $applicantId = $session->get(Access::SESSION_APPLICANT);
        $invitationId = $session->get(Access::SESSION_INVITATION);

        if (! $applicantId || ! $invitationId) {
            return redirect()->route('portal.access');
        }

        $applicant = Applicant::find($applicantId);
        $invitation = ApplicantInvitation::find($invitationId);
        $now = now()->getTimestamp();
        $idle = (int) config('portal.applicant_access.idle_timeout_minutes', 30) * 60;
        $absolute = (int) config('portal.applicant_access.absolute_timeout_minutes', 480) * 60;

        $reason = match (true) {
            ! $applicant || ! $invitation || $invitation->applicant_id !== $applicant->id => 'invalid',
            $invitation->isRevoked() => 'invitation_revoked',
            $invitation->isExpired() => 'invitation_expired',
            $now - (int) $session->get(Access::SESSION_LAST_ACTIVITY, 0) > $idle => 'idle_timeout',
            $now - (int) $session->get(Access::SESSION_STARTED, 0) > $absolute => 'absolute_timeout',
            default => null,
        };

        if ($reason !== null) {
            $this->access->signOut($request, $applicant, $reason);

            return redirect()->route('portal.access')
                ->with('portal_notice', 'Your session has ended. Please sign in again using the link in your invitation email.');
        }

        $session->put(Access::SESSION_LAST_ACTIVITY, $now);
        $this->current->set($applicant, $invitation);

        return $next($request);
    }
}
