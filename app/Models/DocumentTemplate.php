<?php

namespace App\Models;

use App\Enums\InputMode;
use App\Enums\SignatureMode;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $document_type_label
 * @property string|null $description
 * @property string|null $instructions
 * @property InputMode $input_mode
 * @property SignatureMode $signature_mode
 * @property bool $required_by_default
 * @property bool $auto_assign
 * @property list<string> $allowed_extensions
 * @property int $max_size_kb
 * @property list<array{key: string, label: string, required: bool}>|null $supporting_requirements
 * @property string|null $filename_pattern
 * @property string|null $print_asset_path
 * @property string|null $print_asset_name
 * @property string|null $print_asset_sha256
 * @property int $sort_order
 * @property bool $active
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'document_type_label', 'description', 'instructions', 'input_mode',
        'signature_mode', 'required_by_default', 'auto_assign', 'allowed_extensions', 'max_size_kb',
        'supporting_requirements', 'filename_pattern', 'sort_order', 'active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'description' => null,
        'instructions' => null,
        'supporting_requirements' => null,
        'filename_pattern' => null,
        'print_asset_path' => null,
        'print_asset_name' => null,
        'print_asset_sha256' => null,
        'required_by_default' => true,
        'auto_assign' => false,
        'sort_order' => 100,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'input_mode' => InputMode::class,
            'signature_mode' => SignatureMode::class,
            'required_by_default' => 'boolean',
            'auto_assign' => 'boolean',
            'allowed_extensions' => 'array',
            'supporting_requirements' => 'array',
            'max_size_kb' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<DocumentField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<SignatureModeApproval, $this> */
    public function signatureApprovals(): HasMany
    {
        return $this->hasMany(SignatureModeApproval::class)->latest('authorized_at');
    }

    /**
     * @return list<array{key: string, label: string, required: bool}>
     */
    public function supportingRequirements(): array
    {
        return array_values($this->supporting_requirements ?? []);
    }

    public function needsMainUpload(InputMode $input, SignatureMode $signature): bool
    {
        return $input === InputMode::Upload || $signature === SignatureMode::WetSignatureUpload;
    }

    public function hasPrintAsset(): bool
    {
        return $this->print_asset_path !== null;
    }
}
