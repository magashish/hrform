<?php

namespace App\Http\Middleware;

use App\Services\Hr\HrEmailCodeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For pages that must stay reachable for TOTP enrolment: only enforce the
 * second factor when the email method is in use.
 */
class RequireEmailCodeIfEmailMethod
{
    public function __construct(private readonly EnsureTwoFactorEnabled $inner) {}

    public function handle(Request $request, Closure $next): Response
    {
        return HrEmailCodeService::usesEmail() ? $this->inner->handle($request, $next) : $next($request);
    }
}
