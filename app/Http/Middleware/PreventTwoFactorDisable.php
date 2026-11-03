<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to Fortify's routes: when 2FA is mandatory, HR users may not
 * switch it off. (Regenerating recovery codes is still allowed.)
 */
class PreventTwoFactorDisable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('portal.hr.two_factor_required')
            && $request->routeIs('two-factor.disable')
        ) {
            abort(403, 'Two-factor authentication is required for HR accounts.');
        }

        return $next($request);
    }
}
