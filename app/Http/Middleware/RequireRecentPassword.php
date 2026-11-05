<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replacement for Laravel's "password.confirm" middleware. For form posts it
 * returns the user to the page they came from after confirming (instead of
 * redirecting to the POST URL, which cannot be replayed as a GET).
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeout = (int) config('auth.password_timeout', 900);
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt <= $timeout) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'Password confirmation required.');
        }

        if ($request->isMethod('GET')) {
            return redirect()->guest(route('password.confirm'));
        }

        $request->session()->put('url.intended', url()->previous());

        return redirect()->route('password.confirm')
            ->with('status', 'Please confirm your password, then repeat the action.');
    }
}
