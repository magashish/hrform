<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Enums\SignatureMode;
use App\Events\DocumentSubmitted;
use App\Events\SupportingFileUploaded;
use App\Models\ApplicantDocument;
use App\Models\AuditEvent;
use App\Models\CorrectionRequest;
use App\Models\DocumentVersion;
use App\Models\SupportingFile;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFileStore;
use App\Services\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Actions an applicant performs on their own assigned documents. Callers
 * must resolve $document through the current applicant (ownership).
 */
class ApplicantDocumentService
{
    public function __construct(
        private readonly FormService $forms,
        private readonly DocumentReadiness $readiness,
        private readonly DocumentStateMachine $states,
        private readonly ApplicantProgressService $progress,
        private readonly PrivateFileStore $files,
        private readonly FilenameGenerator $names,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveDraft(ApplicantDocument $document, array $input): void
    {
        $this->assertEditable($document);
        $data = $this->forms->validate($document, $input, submitting: false);

        DB::transaction(function () use ($document, $data) {
            $document->form_data = $data;
            $document->save();
            $this->refreshDraft($document);
            $this->auditApplicant('document.draft_saved', $document);
        });
    }

    public function acknowledge(ApplicantDocument $document): void
    {
        $this->assertEditable($document);

        DB::transaction(function () use ($document) {
            $document->acknowledged_at = now();
            $document->save();
            $this->refreshDraft($document);
            $this->auditApplicant('document.acknowledged', $document);
        });
    }

    public function uploadMain(ApplicantDocument $document, UploadedFile $file): DocumentVersion
    {
        $this->assertEditable($document);
        $template = $document->template;
        $applicant = $document->applicant;

        $stored = $this->files->store($file, 'applicants/'.$applicant->applicant_ref, $template->allowed_extensions, $this->maxKb($template->max_size_kb), 'file');

        $version = DB::transaction(function () use ($document, $stored, $applicant, $template) {
            $number = (int) $document->versions()->max('version_number') + 1;
            $previous = $document->currentVersion;

            $version = $document->versions()->create([
                'version_number' => $number,
                'disk' => $stored->disk,
                'storage_path' => $stored->path,
                'original_filename' => $stored->originalName,
                'generated_filename' => $this->names->forDocument($applicant, $template->document_type_label, $stored->extension, now(), version: $number, pattern: $template->filename_pattern),
                'mime_type' => $stored->mime,
                'size' => $stored->size,
                'sha256' => $stored->sha256,
                'scan_status' => $stored->scanStatus,
                'uploaded_by_type' => AuditEvent::ACTOR_APPLICANT,
                'uploaded_by_id' => $applicant->applicant_ref,
                'uploaded_at' => now(),
            ]);

            if ($previous) {
                $previous->forceFill(['superseded_at' => now()])->save();
                $this->maybeDeleteSuperseded($previous->disk, $previous->storage_path, fn () => $previous->delete());
            }

            $document->current_version_id = $version->id;
            $document->save();
            $this->refreshDraft($document);

            $this->auditApplicant('document.uploaded', $document, [
                'version' => $number,
                'size' => $stored->size,
                'sha256_prefix' => substr($stored->sha256, 0, 12),
                'scan' => $stored->scanStatus->value,
                'replacement' => $previous !== null,
            ]);

            return $version;
        });

        SupportingFileUploaded::dispatch($document, $version->version_number > 1);

        return $version;
    }

    public function uploadSupporting(ApplicantDocument $document, string $requirementKey, UploadedFile $file): SupportingFile
    {
        $this->assertEditable($document);
        $template = $document->template;
        $applicant = $document->applicant;

        $requirement = collect($template->supportingRequirements())->firstWhere('key', $requirementKey);
        abort_unless($requirement, 404);

        $stored = $this->files->store($file, 'applicants/'.$applicant->applicant_ref, $template->allowed_extensions, $this->maxKb($template->max_size_kb), 'supporting_file');

        $supporting = DB::transaction(function () use ($document, $requirementKey, $requirement, $stored, $applicant, $template) {
            $previous = $document->currentSupportingFiles()->where('requirement_key', $requirementKey)->first();
            $number = (int) $document->supportingFiles()->where('requirement_key', $requirementKey)->max('version_number') + 1;

            $supporting = $document->supportingFiles()->create([
                'requirement_key' => $requirementKey,
                'version_number' => $number,
                'disk' => $stored->disk,
                'storage_path' => $stored->path,
                'original_filename' => $stored->originalName,
                'generated_filename' => $this->names->forDocument($applicant, $template->document_type_label, $stored->extension, now(), $requirement['label'], $number, $template->filename_pattern),
                'mime_type' => $stored->mime,
                'size' => $stored->size,
                'sha256' => $stored->sha256,
                'scan_status' => $stored->scanStatus,
                'uploaded_at' => now(),
            ]);

            if ($previous) {
                $previous->forceFill(['superseded_at' => now()])->save();
                $this->maybeDeleteSuperseded($previous->disk, $previous->storage_path, fn () => $previous->delete());
            }

            $this->refreshDraft($document);

            $this->auditApplicant('supporting.uploaded', $document, [
                'requirement' => $requirementKey,
                'version' => $number,
                'size' => $stored->size,
                'sha256_prefix' => substr($stored->sha256, 0, 12),
                'scan' => $stored->scanStatus->value,
                'replacement' => $previous !== null,
            ]);

            return $supporting;
        });

        SupportingFileUploaded::dispatch($document, $supporting->version_number > 1);

        return $supporting;
    }

    public function submit(ApplicantDocument $document): void
    {
        $this->assertEditable($document);

        if ($document->hasForm()) {
            // Throws a ValidationException listing what is missing.
            $this->forms->validate($document, $document->form_data ?? [], submitting: true);
        }

        if (! $this->readiness->isReady($document)) {
            throw ValidationException::withMessages(['submit' => 'Please complete every required item before submitting.']);
        }

        DB::transaction(function () use ($document) {
            $wasCorrection = $document->status === DocumentStatus::CorrectionRequired;
            $this->generateCompletedPdf($document);
            $this->states->transition($document, DocumentStatus::Submitted);
            $document->submitted_at = now();
            $document->save();

            $document->openCorrections()->update(['status' => CorrectionRequest::STATUS_RESOLVED, 'resolved_at' => now()]);

            $this->auditApplicant('document.submitted', $document, ['resubmission' => $wasCorrection]);
            $this->progress->refresh($document->applicant);
        });

        DocumentSubmitted::dispatch($document);
    }

    /**
     * For online forms that need no applicant signature (e.g. the Reference
     * form), type the answers onto the form's PDF and file it as the
     * submitted document, named per the naming convention.
     */
    private function generateCompletedPdf(ApplicantDocument $document): void
    {
        $template = $document->template;
        if (! $document->hasForm() || $document->signature_mode_snapshot !== SignatureMode::None || ! $template->hasPrefill()) {
            return;
        }

        $applicant = $document->applicant;
        $bytes = app(PdfPrefillService::class)->render($document);
        $disk = (string) config('portal.storage.documents_disk', 'documents');
        $path = 'applicants/'.$applicant->applicant_ref.'/'.Str::uuid().'.bin';
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);

        $number = (int) $document->versions()->max('version_number') + 1;
        $previous = $document->currentVersion;
        $sha = hash('sha256', $bytes);

        $version = $document->versions()->create([
            'version_number' => $number,
            'disk' => $disk,
            'storage_path' => $path,
            'original_filename' => 'completed online',
            'generated_filename' => $this->names->forDocument($applicant, $template->document_type_label, 'pdf', now(), version: $number, pattern: $template->filename_pattern),
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            'sha256' => $sha,
            'scan_status' => ScanStatus::NotScanned,
            'uploaded_by_type' => AuditEvent::ACTOR_SYSTEM,
            'uploaded_by_id' => null,
            'uploaded_at' => now(),
        ]);

        if ($previous) {
            $previous->forceFill(['superseded_at' => now()])->save();
        }

        $document->current_version_id = $version->id;
        $document->save();

        $this->auditApplicant('document.generated', $document, ['version' => $number, 'sha256_prefix' => substr($sha, 0, 12)]);
    }

    private function refreshDraft(ApplicantDocument $document): void
    {
        $document->unsetRelation('currentSupportingFiles');

        if ($document->status !== DocumentStatus::CorrectionRequired) {
            $this->states->transition($document, $this->readiness->draftStatus($document));
        }

        $this->progress->refresh($document->applicant);
    }

    private function assertEditable(ApplicantDocument $document): void
    {
        if (! $document->status->isEditableByApplicant()) {
            throw ValidationException::withMessages([
                'document' => 'This document has been submitted and can no longer be changed. If something needs correcting, HR will contact you.',
            ]);
        }
    }

    private function maxKb(int $templateMax): int
    {
        return min($templateMax ?: (int) $this->settings->get('default_max_upload_kb'), (int) config('portal.files.hard_max_upload_kb'));
    }

    private function maybeDeleteSuperseded(string $disk, string $path, callable $deleteRow): void
    {
        if (config('portal.files.delete_superseded') && $this->files->delete($disk, $path)) {
            $deleteRow();
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function auditApplicant(string $event, ApplicantDocument $document, array $metadata = []): void
    {
        $applicant = $document->applicant;
        $this->audit->record($event, $document, ['template' => $document->template->code] + $metadata,
            applicantRef: $applicant->applicant_ref,
            actorType: AuditEvent::ACTOR_APPLICANT,
            actorId: $applicant->applicant_ref,
        );
    }
}
