<?php

namespace App\Services\Templates;

use App\Enums\SignatureMode;
use App\Models\DocumentField;
use App\Models\DocumentTemplate;
use App\Models\SignatureModeApproval;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\FieldTypeRegistry;
use App\Services\Files\PrivateFileStore;
use App\Services\Signatures\SignatureProviderManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TemplateService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FieldTypeRegistry $types,
        private readonly PrivateFileStore $files,
        private readonly SignatureProviderManager $esign,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?DocumentTemplate $template, array $data, User $actor): DocumentTemplate
    {
        $creating = $template === null;
        $template ??= new DocumentTemplate;

        $mode = SignatureMode::from($data['signature_mode']);
        if ($mode === SignatureMode::ProviderEsign) {
            $this->assertEsignAllowed($template);
        }

        return DB::transaction(function () use ($template, $data, $actor, $creating) {
            $template->fill($data);
            $changed = array_keys($template->getDirty());
            $template->save();

            $this->audit->record($creating ? 'template.created' : 'template.updated', $template, [
                'template' => $template->code,
                'fields' => implode(',', $changed),
            ], hrActor: $actor);

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated: field_key, label, help_text, type, required, sensitive, sort_order
     * @param  array<string, mixed>  $configInput
     */
    public function addField(DocumentTemplate $template, array $data, array $configInput, User $actor): DocumentField
    {
        $config = $this->types->normalizeConfig($data['type'], $configInput);

        if ($template->fields()->where('field_key', $data['field_key'])->exists()) {
            throw ValidationException::withMessages(['field_key' => 'This key is already used on this template.']);
        }

        $field = $template->fields()->create([
            'field_key' => $data['field_key'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'type' => $data['type'],
            'config' => $config ?: null,
            'required' => (bool) ($data['required'] ?? false),
            'sensitive' => $data['type'] === 'ssn' || (bool) ($data['sensitive'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 100),
        ]);

        $this->audit->record('template.field_added', $template, [
            'template' => $template->code, 'field' => $field->field_key, 'type' => $field->type,
        ], hrActor: $actor);

        return $field;
    }

    public function removeField(DocumentField $field, User $actor): void
    {
        $template = $field->template;
        $key = $field->field_key;
        $field->delete();

        $this->audit->record('template.field_removed', $template, ['template' => $template->code, 'field' => $key], hrActor: $actor);
    }

    public function uploadPrintAsset(DocumentTemplate $template, UploadedFile $file, User $actor): void
    {
        $stored = $this->files->store($file, 'templates', ['pdf'], (int) config('portal.files.hard_max_upload_kb'), 'print_asset');
        $old = $template->print_asset_path;

        $template->forceFill([
            'print_asset_path' => $stored->path,
            'print_asset_name' => $stored->originalName,
            'print_asset_sha256' => $stored->sha256,
        ])->save();

        if ($old) {
            $this->files->delete($stored->disk, $old);
        }

        $this->audit->record('template.print_asset_uploaded', $template, [
            'template' => $template->code, 'sha256_prefix' => substr($stored->sha256, 0, 12),
        ], hrActor: $actor);
    }

    /**
     * @param  array{signature_mode: string, authorized_by_name: string, approval_reference: string, notes?: string|null, authorized_at: string}  $data
     */
    public function recordApproval(DocumentTemplate $template, array $data, User $actor): SignatureModeApproval
    {
        $approval = $template->signatureApprovals()->create([
            'signature_mode' => $data['signature_mode'],
            'authorized_by_name' => $data['authorized_by_name'],
            'approval_reference' => $data['approval_reference'],
            'notes' => $data['notes'] ?? null,
            'authorized_at' => $data['authorized_at'],
            'recorded_by' => $actor->id,
        ]);

        $this->audit->record('template.signature_approval_recorded', $template, [
            'template' => $template->code, 'mode' => $data['signature_mode'],
        ], hrActor: $actor);

        return $approval;
    }

    private function assertEsignAllowed(DocumentTemplate $template): void
    {
        if (! $this->esign->isAvailable()) {
            throw ValidationException::withMessages([
                'signature_mode' => 'Electronic signature is not available: no e-signature provider has been selected and configured.',
            ]);
        }

        $approved = $template->exists
            && $template->signatureApprovals()->where('signature_mode', SignatureMode::ProviderEsign->value)->exists();

        if (! $approved) {
            throw ValidationException::withMessages([
                'signature_mode' => 'Record the written client/legal approval for electronic signature on this document before enabling it.',
            ]);
        }
    }
}
