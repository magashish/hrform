<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Enrolment page for HR TOTP 2FA. Enabling, confirming and regenerating
 * recovery codes are handled by Fortify's endpoints; this page renders the
 * current state. The route requires a recent password confirmation.
 */
class TwoFactorSetupController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $status = $request->session()->get('status');

        $pendingConfirmation = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        // Recovery codes are displayed only immediately after confirmation
        // or regeneration, never on ordinary page views.
        $showRecoveryCodes = $user->hasConfirmedTwoFactor()
            && in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);

        return view('hr.security.two-factor', [
            'user' => $user,
            'pendingConfirmation' => $pendingConfirmation,
            'qrSvg' => $pendingConfirmation ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $pendingConfirmation ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $showRecoveryCodes ? $user->recoveryCodes() : [],
            'required' => (bool) config('portal.hr.two_factor_required'),
            'method' => config('portal.hr.two_factor_method'),
        ]);
    }
}
