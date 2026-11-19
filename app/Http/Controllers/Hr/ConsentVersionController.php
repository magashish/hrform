<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\ConsentVersion;
use App\Services\Applicants\ConsentService;
use App\Support\FileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notices applicants acknowledge before any form (Contingent Job Offer,
 * FCRA summary of rights, privacy disclosure). Each type is versioned; text
 * is immutable once saved, so a change means a new version.
 */
class ConsentVersionController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    public function index(): View
    {
        $versions = ConsentVersion::withCount('acknowledgements')->latest('id')->get()->groupBy('notice_key');

        return view('hr.consent.index', [
            'notices' => config('portal.notices'),
            'versions' => $versions,
        ]);
    }

    public function create(Request $request): View
    {
        $key = array_key_exists((string) $request->query('notice'), (array) config('portal.notices')) ? (string) $request->query('notice') : 'disclosure';

        return view('hr.consent.create', [
            'notices' => config('portal.notices'),
            'noticeKey' => $key,
            'latest' => ConsentVersion::where('notice_key', $key)->latest('id')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notice_key' => ['required', Rule::in(array_keys((array) config('portal.notices')))],
            'version' => ['required', 'string', 'max:40', Rule::unique('consent_versions', 'version')->where('notice_key', $request->input('notice_key'))],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:50000'],
            'attachment' => ['nullable', 'file'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $this->consents->createVersion($data['version'], $data['title'], $data['body'], $request->user(),
            $request->boolean('activate'), $data['notice_key'], $request->file('attachment'));

        return redirect()->route('hr.consent.index')->with('status', 'Notice version saved.');
    }

    public function show(ConsentVersion $consent): View
    {
        return view('hr.consent.show', ['version' => $consent]);
    }

    public function attachment(ConsentVersion $consent): StreamedResponse
    {
        abort_unless($consent->attachment_path, 404);

        return FileResponse::make((string) config('portal.storage.documents_disk'), $consent->attachment_path,
            $consent->attachment_name ?: 'notice.pdf', 'application/pdf', inline: true);
    }

    public function activate(Request $request, ConsentVersion $consent): RedirectResponse
    {
        $this->consents->activate($consent, $request->user());

        return back()->with('status', "\"{$consent->noticeLabel()}\" version {$consent->version} is now active. Applicants will be asked to acknowledge it.");
    }
}
