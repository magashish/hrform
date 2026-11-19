<?php

namespace App\Http\Controllers\Hr;

use App\Enums\SignatureMode;
use App\Http\Controllers\Controller;
use App\Mail\ApplicantNoticeMail;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\DocumentTemplate;
use App\Services\Applicants\InvitationService;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\AssignmentService;
use App\Services\Documents\FieldTypeRegistry;
use App\Services\Documents\ReviewService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;

class DocumentReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $review,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, Applicant $applicant, ApplicantDocument $document, FieldTypeRegistry $types): View
    {
        Gate::authorize('review', $applicant);
        $this->assertBelongs($applicant, $document);

        $document->load(['template.fields', 'versions', 'supportingFiles', 'corrections.requester']);
        $this->review->markUnderReview($document, $request->user());
        $this->audit->record('document.viewed', $document, ['template' => $document->template->code],
            applicantRef: $applicant->applicant_ref);

        return view('hr.documents.show', [
            'applicant' => $applicant,
            'document' => $document,
            'values' => $document->form_data ?? [],
            'revealed' => (array) $request->session()->get('revealed.'.$document->id, []),
            'types' => $types,
        ]);
    }

    public function reveal(Request $request, Applicant $applicant, ApplicantDocument $document): RedirectResponse
    {
        Gate::authorize('review', $applicant);
        $this->assertBelongs($applicant, $document);

        $key = (string) $request->input('field');
        $field = $document->template->fields()->where('field_key', $key)->firstOrFail();

        $this->audit->record('sensitive_field.revealed', $document, ['template' => $document->template->code, 'field' => $field->field_key],
            applicantRef: $applicant->applicant_ref);

        // Revealed for this page view only (flashed, not persisted).
        $revealed = (array) $request->session()->get('revealed.'.$document->id, []);
        $request->session()->flash('revealed.'.$document->id, array_values(array_unique([...$revealed, $field->field_key])));

        return redirect()->route('hr.documents.show', [$applicant, $document]);
    }

    public function approve(Request $request, Applicant $applicant, ApplicantDocument $document): RedirectResponse
    {
        Gate::authorize('review', $applicant);
        $this->assertBelongs($applicant, $document);

        $wet = $document->signature_mode_snapshot === SignatureMode::WetSignatureUpload;
        if ($wet) {
            $request->validate(['signature_checked' => ['accepted']], [
                'signature_checked.accepted' => 'Confirm that the uploaded document carries a handwritten signature (not typed or electronic) before approving.',
            ]);
        }

        try {
            $this->review->approve($document, $request->user(), $wet);
        } catch (LogicException) {
            throw ValidationException::withMessages(['document' => 'This document cannot be approved in its current state.']);
        }

        return redirect()->route('hr.applicants.show', $applicant)->with('status', '"'.$document->template->name.'" approved.');
    }

    public function requestCorrection(Request $request, Applicant $applicant, ApplicantDocument $document): RedirectResponse
    {
        Gate::authorize('review', $applicant);
        $this->assertBelongs($applicant, $document);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $this->review->requestCorrection($document, $request->user(), $data['message'], $data['scope'] ?: 'document');
        } catch (LogicException) {
            throw ValidationException::withMessages(['document' => 'A correction cannot be requested for this document in its current state.']);
        }

        return redirect()->route('hr.applicants.show', $applicant)->with('status', 'Correction requested. The applicant has been emailed.');
    }

    public function assign(Request $request, Applicant $applicant, AssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('update', $applicant);
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'required' => ['sometimes', 'boolean'],
            'stage' => ['nullable', Rule::in(array_keys((array) config('portal.stages')))],
            'release_now' => ['sometimes', 'boolean'],
        ]);

        $template = DocumentTemplate::where('active', true)->findOrFail($data['template_id']);
        $doc = $assignments->assign($applicant, $template, $request->user(), $request->boolean('required'),
            $data['stage'] ?? null, $request->has('release_now') ? $request->boolean('release_now') : null);

        return back()->with('status', '"'.$template->name.'" assigned'.($doc->isReleased()
            ? ' and visible to the applicant.'
            : ' and held until you release "'.$doc->stageLabel().'".'));
    }

    /**
     * Makes a stage's documents visible to the applicant and tells them.
     * If their invitation has expired, a new one is sent (progress is kept).
     */
    public function releaseStage(Request $request, Applicant $applicant, AssignmentService $assignments,
        InvitationService $invitations, SettingsService $settings): RedirectResponse
    {
        Gate::authorize('invite', $applicant);
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys((array) config('portal.stages')))]]);

        $count = $assignments->releaseStage($applicant, $data['stage'], $request->user());
        if ($count === 0) {
            return back()->with('status', 'There were no held documents in that stage.');
        }

        if ($applicant->activeInvitation()->exists()) {
            Mail::to($applicant->email)->queue(new ApplicantNoticeMail(
                $applicant,
                (string) $settings->get('mail_release_subject'),
                (string) $settings->get('mail_release_intro'),
            ));
            $message = "Released {$count} document(s). The applicant has been emailed.";
        } else {
            $invitations->issue($applicant, $request->user());
            $message = "Released {$count} document(s). Their invitation had expired, so a new one has been emailed (progress is kept).";
        }

        return back()->with('status', $message);
    }

    public function unassign(Request $request, Applicant $applicant, ApplicantDocument $document, AssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('update', $applicant);
        $this->assertBelongs($applicant, $document);

        try {
            $assignments->unassign($document, $request->user());
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['document' => $e->getMessage()]);
        }

        return back()->with('status', 'Document removed from this applicant.');
    }

    private function assertBelongs(Applicant $applicant, ApplicantDocument $document): void
    {
        abort_unless($document->applicant_id === $applicant->id, 404);
        $document->setRelation('applicant', $applicant);
    }
}
