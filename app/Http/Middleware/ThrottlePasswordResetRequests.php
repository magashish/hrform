<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP limit on HR password-reset link requests (Fortify's route has
 * no limiter of its own; the broker only throttles per email address).
 */
class ThrottlePasswordResetRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->routeIs('password.email')) {
            $key = 'password-reset-request:'.$request->ip();

            if (RateLimiter::tooManyAttempts($key, 5)) {
                abort(429);
            }

            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
