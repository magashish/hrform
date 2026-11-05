<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every response.
 *
 * The CSP allows no inline script or style: views must use the static
 * files in public/css and public/js only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        // File responses set their own stricter (sandboxed) policy.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Every PHP-served page may contain personal data: never cache.
        // (Static CSS/JS is served by the web server and is unaffected.)
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');

        if (config('portal.security.hsts') && $request->isSecure()) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('portal.security.hsts_max_age').'; includeSubDomains'
            );
        }

        return $response;
    }
}
