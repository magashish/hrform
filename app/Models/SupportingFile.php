<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $applicant_document_id
 * @property string $requirement_key
 * @property int $version_number
 * @property string $disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $generated_filename
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property ScanStatus $scan_status
 * @property Carbon $uploaded_at
 * @property Carbon|null $superseded_at
 */
class SupportingFile extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['storage_path', 'disk'];

    /** @var array<string, mixed> */
    protected $attributes = ['superseded_at' => null];

    protected function casts(): array
    {
        return [
            'scan_status' => ScanStatus::class,
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<ApplicantDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicantDocument::class, 'applicant_document_id');
    }
}
