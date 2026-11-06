<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Hr\HrEmailCodeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the HR second factor before any HR page:
 *  - email method: the emailed sign-in code must have been entered this session;
 *  - totp method:  the user must have enrolled and confirmed an authenticator app
 *                  (Fortify then challenges for it at every login).
 */
class EnsureTwoFactorEnabled
{
    public function __construct(private readonly HrEmailCodeService $codes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('portal.hr.two_factor_required') || ! $user instanceof User) {
            return $next($request);
        }

        if (config('portal.hr.two_factor_method') === 'email') {
            if (! $this->codes->hasPassed($request, $user)) {
                if ($request->expectsJson()) {
                    abort(403, 'Sign-in code required.');
                }
                if ($request->isMethod('GET')) {
                    $request->session()->put('url.intended', $request->fullUrl());
                }

                return redirect()->route('hr.email-code');
            }

            return $next($request);
        }

        if (! $user->hasConfirmedTwoFactor()) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication must be enabled.');
            }

            return redirect()->route('hr.security.two-factor');
        }

        return $next($request);
    }
}
