<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\ConsentVersion;
use App\Services\Applicants\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Disclosure/consent versions. Text is immutable once created; a change
 * means a new version, and applicants must acknowledge the active one.
 */
class ConsentVersionController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    public function index(): View
    {
        return view('hr.consent.index', [
            'versions' => ConsentVersion::withCount('acknowledgements')->latest('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('hr.consent.create', ['latest' => ConsentVersion::latest('id')->first()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', Rule::unique('consent_versions', 'version')],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:50000'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $this->consents->createVersion($data['version'], $data['title'], $data['body'], $request->user(), $request->boolean('activate'));

        return redirect()->route('hr.consent.index')->with('status', 'Consent version saved.');
    }

    public function show(ConsentVersion $consent): View
    {
        return view('hr.consent.show', ['version' => $consent]);
    }

    public function activate(Request $request, ConsentVersion $consent): RedirectResponse
    {
        $this->consents->activate($consent, $request->user());

        return back()->with('status', "Version {$consent->version} is now active. Applicants will be asked to acknowledge it.");
    }
}
