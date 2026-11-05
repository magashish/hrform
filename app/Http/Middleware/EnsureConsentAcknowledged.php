<?php

namespace App\Http\Middleware;

use App\Models\ConsentVersion;
use App\Services\Applicants\CurrentApplicant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applicants must acknowledge the current disclosure/consent version
 * before reaching any form or document.
 */
class EnsureConsentAcknowledged
{
    public function __construct(private readonly CurrentApplicant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $version = ConsentVersion::current();

        if (! $version || ! $this->current->get()->hasAcknowledged($version)) {
            return redirect()->route('portal.consent');
        }

        return $next($request);
    }
}
