<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Jobs\BuildApplicantZip;
use App\Models\Applicant;
use App\Models\ExportArchive;
use App\Services\Audit\AuditLogger;
use App\Support\FileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Applicant $applicant): RedirectResponse
    {
        Gate::authorize('download', $applicant);

        $export = ExportArchive::create([
            'applicant_id' => $applicant->id,
            'requested_by' => $request->user()->id,
            'status' => ExportArchive::PENDING,
        ]);

        $this->audit->record('export.requested', $export, applicantRef: $applicant->applicant_ref);
        BuildApplicantZip::dispatch($export->id);

        return back()->with('status', 'The ZIP is being prepared. Refresh this page in a moment to download it.');
    }

    /**
     * Only the HR user who requested the ZIP can download it, and only
     * until it expires.
     */
    public function download(Request $request, Applicant $applicant, ExportArchive $export): StreamedResponse
    {
        Gate::authorize('download', $applicant);
        abort_unless($export->applicant_id === $applicant->id && $export->requested_by === $request->user()->id, 404);
        abort_unless($export->isDownloadable(), 410);

        $export->forceFill(['downloaded_at' => now()])->save();
        $this->audit->record('export.downloaded', $export, applicantRef: $applicant->applicant_ref);

        return FileResponse::make((string) $export->disk, (string) $export->storage_path, (string) $export->download_name, 'application/zip');
    }
}
