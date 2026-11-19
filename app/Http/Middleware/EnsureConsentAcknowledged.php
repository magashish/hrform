<?php

namespace App\Http\Middleware;

use App\Models\ConsentVersion;
use App\Services\Applicants\CurrentApplicant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applicants must acknowledge every active notice (Contingent Job Offer,
 * FCRA summary of rights, privacy disclosure, ...) before any form.
 */
class EnsureConsentAcknowledged
{
    public function __construct(private readonly CurrentApplicant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (ConsentVersion::activeNotices()->isEmpty() || $this->current->get()->pendingNotice() !== null) {
            return redirect()->route('portal.consent');
        }

        return $next($request);
    }
}
