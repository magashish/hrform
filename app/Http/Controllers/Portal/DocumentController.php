<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ApplicantDocument;
use App\Services\Applicants\CurrentApplicant;
use App\Services\Documents\ApplicantDocumentService;
use App\Services\Documents\DocumentReadiness;
use App\Services\Documents\FieldTypeRegistry;
use App\Support\FileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every action resolves the document through the signed-in applicant's own
 * documents. A document ID belonging to anyone else returns 404.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly CurrentApplicant $current,
        private readonly ApplicantDocumentService $service,
    ) {}

    public function show(string $document, DocumentReadiness $readiness, FieldTypeRegistry $types): View
    {
        $doc = $this->resolve($document, ['template.fields', 'currentVersion', 'currentSupportingFiles', 'openCorrections']);

        return view('portal.document', [
            'document' => $doc,
            'template' => $doc->template,
            'missing' => $readiness->missing($doc),
            'editable' => $doc->status->isEditableByApplicant(),
            'values' => $doc->form_data ?? [],
            'types' => $types,
        ]);
    }

    public function saveDraft(Request $request, string $document): RedirectResponse
    {
        $doc = $this->resolve($document, ['template.fields']);
        $this->service->saveDraft($doc, $request->except(['_token', '_method']));

        if ($request->input('next') === 'review') {
            return redirect()->route('portal.documents.review', $doc->id);
        }

        return redirect()->route('portal.documents.show', $doc->id)->with('portal_notice', 'Your answers have been saved.');
    }

    public function acknowledge(Request $request, string $document): RedirectResponse
    {
        $doc = $this->resolve($document);
        $request->validate(['acknowledge' => ['accepted']], ['acknowledge.accepted' => 'Please tick the box to confirm you have read this document.']);
        $this->service->acknowledge($doc);

        return redirect()->route('portal.documents.review', $doc->id);
    }

    public function upload(Request $request, string $document): RedirectResponse
    {
        $doc = $this->resolve($document, ['template']);
        $request->validate(['file' => ['required', 'file']]);
        $this->service->uploadMain($doc, $request->file('file'));

        return redirect()->route('portal.documents.show', $doc->id)->with('portal_notice', 'File uploaded.');
    }

    public function uploadSupporting(Request $request, string $document, string $requirement): RedirectResponse
    {
        $doc = $this->resolve($document, ['template']);
        $request->validate(['supporting_file' => ['required', 'file']]);
        $this->service->uploadSupporting($doc, $requirement, $request->file('supporting_file'));

        return redirect()->route('portal.documents.show', $doc->id)->with('portal_notice', 'File uploaded.');
    }

    public function review(string $document, DocumentReadiness $readiness, FieldTypeRegistry $types): View
    {
        $doc = $this->resolve($document, ['template.fields', 'currentVersion', 'currentSupportingFiles']);

        return view('portal.review', [
            'document' => $doc,
            'template' => $doc->template,
            'ready' => $readiness->isReady($doc),
            'missing' => $readiness->missing($doc),
            'editable' => $doc->status->isEditableByApplicant(),
            'values' => $doc->form_data ?? [],
            'types' => $types,
        ]);
    }

    public function submit(Request $request, string $document): RedirectResponse
    {
        $doc = $this->resolve($document, ['template.fields']);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Please confirm the information is accurate and complete.']);
        $this->service->submit($doc);

        $remaining = $this->current->get()->documents()->where('required', true)->get()
            ->reject(fn ($d) => $d->status->isSubmittedOrLater())->count();

        return $remaining === 0
            ? redirect()->route('portal.complete')
            : redirect()->route('portal.home')->with('portal_notice', '"'.$doc->template->name.'" has been submitted.');
    }

    public function printForm(string $document): StreamedResponse
    {
        $doc = $this->resolve($document, ['template']);
        $template = $doc->template;
        abort_unless($template->hasPrintAsset(), 404);

        $name = ($template->document_type_label ?: 'form').'-to-print.pdf';

        return FileResponse::make((string) config('portal.storage.documents_disk'), (string) $template->print_asset_path, $name, 'application/pdf');
    }

    public function downloadOwn(string $document, string $kind, int $file): StreamedResponse
    {
        $doc = $this->resolve($document);
        $record = match ($kind) {
            'version' => $doc->versions()->whereKey($file)->firstOrFail(),
            'supporting' => $doc->supportingFiles()->whereKey($file)->firstOrFail(),
            default => abort(404),
        };

        return FileResponse::make($record->disk, $record->storage_path, $record->generated_filename, $record->mime_type);
    }

    public function complete(): View
    {
        return view('portal.complete', ['applicant' => $this->current->get()]);
    }

    /**
     * @param  list<string>  $with
     */
    private function resolve(string $id, array $with = []): ApplicantDocument
    {
        abort_unless(Str::isUuid($id), 404);

        /** @var ApplicantDocument $doc */
        $doc = $this->current->get()->documents()->with($with)->whereKey($id)->firstOrFail();
        $doc->setRelation('applicant', $this->current->get());

        return $doc;
    }
}
