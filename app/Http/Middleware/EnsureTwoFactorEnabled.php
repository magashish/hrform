<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces HR users to enrol and confirm TOTP 2FA before any HR page.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            config('portal.hr.two_factor_required')
            && $user instanceof User
            && ! $user->hasConfirmedTwoFactor()
        ) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication must be enabled.');
            }

            return redirect()->route('hr.security.two-factor');
        }

        return $next($request);
    }
}
