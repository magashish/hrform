<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ConsentVersion;
use App\Services\Applicants\ConsentService;
use App\Services\Applicants\CurrentApplicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentController extends Controller
{
    public function __construct(private readonly CurrentApplicant $current) {}

    public function show(): View|RedirectResponse
    {
        $version = ConsentVersion::current();

        if ($version && $this->current->get()->hasAcknowledged($version)) {
            return redirect()->route('portal.home');
        }

        return view('portal.consent', ['version' => $version]);
    }

    public function store(Request $request, ConsentService $consents): RedirectResponse
    {
        $version = ConsentVersion::current();
        abort_unless($version, 409);

        $request->validate([
            'version_id' => ['required', 'integer'],
            'acknowledge' => ['accepted'],
        ], ['acknowledge.accepted' => 'You must acknowledge the disclosure to continue.']);

        // The text shown must be the version being acknowledged.
        if ((int) $request->input('version_id') !== $version->id) {
            return redirect()->route('portal.consent')->with('portal_notice', 'The disclosure has been updated. Please review it again.');
        }

        $consents->acknowledge($this->current->get(), $version, $request);

        return redirect()->route('portal.home');
    }
}
