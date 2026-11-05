<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Applicants\ApplicantAccessService;
use App\Services\Applicants\CurrentApplicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccessController extends Controller
{
    public const GENERIC_CODE_ERROR = 'That access code is not valid or has expired. Please check your invitation email or contact HR for a new invitation.';

    public const GENERIC_OTP_ERROR = 'That verification code is not valid or has expired. Request a new code and try again.';

    public function __construct(private readonly ApplicantAccessService $access) {}

    public function show(): View
    {
        return view('portal.access');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        if (! $this->access->verifyCode($request, (string) $request->input('code'))) {
            return back()->withErrors(['code' => self::GENERIC_CODE_ERROR]);
        }

        return redirect()->route('portal.verify');
    }

    public function verify(Request $request): View|RedirectResponse
    {
        if (! $this->access->pendingInvitation($request)) {
            return redirect()->route('portal.access');
        }

        return view('portal.verify', [
            'otpRequested' => (bool) $request->session()->get(ApplicantAccessService::SESSION_OTP_REQUESTED),
        ]);
    }

    public function requestOtp(Request $request): RedirectResponse
    {
        $invitation = $this->access->pendingInvitation($request);
        if (! $invitation) {
            return redirect()->route('portal.access');
        }

        $request->validate(['email' => ['required', 'string', 'max:254']]);
        $this->access->requestOtp($request, $invitation, (string) $request->input('email'));

        return redirect()->route('portal.verify')->with('portal_notice',
            'If that email address matches our records, we have sent a 6-digit code to it. The code expires in '
            .config('portal.applicant_access.otp_ttl_minutes').' minutes.');
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $invitation = $this->access->pendingInvitation($request);
        if (! $invitation) {
            return redirect()->route('portal.access');
        }

        $request->validate(['otp' => ['required', 'string', 'max:12']]);

        if (! $this->access->verifyOtp($request, $invitation, (string) $request->input('otp'))) {
            return back()->withErrors(['otp' => self::GENERIC_OTP_ERROR]);
        }

        return redirect()->route('portal.home');
    }

    public function logout(Request $request, CurrentApplicant $current): RedirectResponse
    {
        $this->access->signOut($request, $current->get());

        return redirect()->route('portal.access')->with('portal_notice', 'You have signed out. Your progress has been saved.');
    }
}
