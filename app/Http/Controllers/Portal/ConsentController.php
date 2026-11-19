<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ConsentVersion;
use App\Services\Applicants\ConsentService;
use App\Services\Applicants\CurrentApplicant;
use App\Support\FileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notices shown one at a time before any form (in the configured order),
 * and viewable again later from the applicant's home page.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly CurrentApplicant $current) {}

    public function show(): View|RedirectResponse
    {
        $applicant = $this->current->get();
        $notices = ConsentVersion::activeNotices();
        $pending = $applicant->pendingNotice();

        if ($notices->isNotEmpty() && $pending === null) {
            return redirect()->route('portal.home');
        }

        return view('portal.consent', [
            'version' => $pending,
            'applicant' => $applicant,
            'step' => $pending ? $notices->search(fn ($v) => $v->id === $pending->id) + 1 : 0,
            'steps' => $notices->count(),
        ]);
    }

    public function store(Request $request, ConsentService $consents): RedirectResponse
    {
        $applicant = $this->current->get();
        $pending = $applicant->pendingNotice();
        abort_unless($pending, 409);

        $request->validate([
            'version_id' => ['required', 'integer'],
            'acknowledge' => ['accepted'],
        ], ['acknowledge.accepted' => 'Please tick the box to confirm before continuing.']);

        // The text shown must be the version being acknowledged.
        if ((int) $request->input('version_id') !== $pending->id) {
            return redirect()->route('portal.consent')->with('portal_notice', 'This notice has been updated. Please review it again.');
        }

        $consents->acknowledge($applicant, $pending, $request);

        return $applicant->pendingNotice() ? redirect()->route('portal.consent') : redirect()->route('portal.home');
    }

    /** Re-read a notice the applicant has acknowledged. */
    public function view(int $version): View
    {
        $notice = $this->acknowledged($version);

        return view('portal.notice', ['version' => $notice, 'applicant' => $this->current->get()]);
    }

    public function attachment(int $version): StreamedResponse
    {
        $notice = ConsentVersion::where('active', true)->findOrFail($version);
        abort_unless($notice->attachment_path, 404);

        return FileResponse::make((string) config('portal.storage.documents_disk'), $notice->attachment_path,
            $notice->attachment_name ?: 'notice.pdf', 'application/pdf', inline: true);
    }

    private function acknowledged(int $id): ConsentVersion
    {
        $applicant = $this->current->get();
        abort_unless($applicant->consents()->where('consent_version_id', $id)->exists(), 404);

        return ConsentVersion::findOrFail($id);
    }
}
