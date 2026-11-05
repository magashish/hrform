<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\SupportingFile;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\ReviewService;
use App\Support\FileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized streaming of private files. The file must belong to the
 * applicant in the URL; every access is audited.
 */
class FileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request, Applicant $applicant, string $kind, int $file): StreamedResponse
    {
        Gate::authorize('download', $applicant);
        $record = $this->resolve($applicant, $kind, $file);
        $inline = $request->boolean('inline');

        $this->audit->record('file.downloaded', $record->document, [
            'template' => $record->document->template->code,
            'kind' => $kind,
            'disposition' => $inline ? 'preview' : 'download',
        ], applicantRef: $applicant->applicant_ref);

        return FileResponse::make($record->disk, $record->storage_path, $record->generated_filename, $record->mime_type, $inline);
    }

    public function destroy(Request $request, Applicant $applicant, string $kind, int $file, ReviewService $review): RedirectResponse
    {
        Gate::authorize('update', $applicant);
        $record = $this->resolve($applicant, $kind, $file);
        $review->deleteFile($record, $request->user());

        return back()->with('status', 'File deleted.');
    }

    public function templateAsset(DocumentTemplate $template): StreamedResponse
    {
        abort_unless($template->hasPrintAsset(), 404);

        return FileResponse::make((string) config('portal.storage.documents_disk'), (string) $template->print_asset_path,
            ($template->document_type_label ?: 'form').'-to-print.pdf', 'application/pdf');
    }

    private function resolve(Applicant $applicant, string $kind, int $id): DocumentVersion|SupportingFile
    {
        $query = match ($kind) {
            'version' => DocumentVersion::query(),
            'supporting' => SupportingFile::query(),
            default => abort(404),
        };

        /** @var DocumentVersion|SupportingFile $record */
        $record = $query->with('document.template')->whereKey($id)
            ->whereHas('document', fn ($q) => $q->where('applicant_id', $applicant->id))
            ->firstOrFail();
        $record->document->setRelation('applicant', $applicant);

        return $record;
    }
}
