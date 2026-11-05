<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\InputMode;
use App\Enums\SignatureMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A template assigned to one applicant. Status changes go through
 * App\Services\Documents\DocumentStateMachine only.
 *
 * @property string $id
 * @property int $applicant_id
 * @property int $document_template_id
 * @property bool $required
 * @property DocumentStatus $status
 * @property array<string, mixed>|null $form_data
 * @property InputMode $input_mode_snapshot
 * @property SignatureMode $signature_mode_snapshot
 * @property int|null $current_version_id
 * @property Carbon $assigned_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewer_id
 */
class ApplicantDocument extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id', 'status'];

    /** @var list<string> */
    protected $hidden = ['form_data'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'form_data' => null,
        'current_version_id' => null,
        'acknowledged_at' => null,
        'submitted_at' => null,
        'reviewed_at' => null,
        'reviewer_id' => null,
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'status' => DocumentStatus::class,
            'form_data' => 'encrypted:array',
            'input_mode_snapshot' => InputMode::class,
            'signature_mode_snapshot' => SignatureMode::class,
            'assigned_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Applicant, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    /** @return HasMany<SupportingFile, $this> */
    public function supportingFiles(): HasMany
    {
        return $this->hasMany(SupportingFile::class)->orderByDesc('version_number');
    }

    /** @return HasMany<SupportingFile, $this> */
    public function currentSupportingFiles(): HasMany
    {
        return $this->hasMany(SupportingFile::class)->whereNull('superseded_at');
    }

    /** @return HasMany<CorrectionRequest, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class)->latest('id');
    }

    /** @return HasMany<CorrectionRequest, $this> */
    public function openCorrections(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class)->where('status', CorrectionRequest::STATUS_OPEN)->latest('id');
    }

    public function needsMainUpload(): bool
    {
        return $this->input_mode_snapshot === InputMode::Upload
            || $this->signature_mode_snapshot === SignatureMode::WetSignatureUpload;
    }

    public function hasForm(): bool
    {
        return $this->input_mode_snapshot === InputMode::OnlineForm;
    }

    public function isAcknowledgement(): bool
    {
        return $this->input_mode_snapshot === InputMode::Acknowledgement;
    }
}
