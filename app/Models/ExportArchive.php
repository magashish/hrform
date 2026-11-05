<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $applicant_id
 * @property int $requested_by
 * @property string $status
 * @property string|null $disk
 * @property string|null $storage_path
 * @property string|null $download_name
 * @property int|null $size
 * @property int|null $file_count
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 */
class ExportArchive extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['storage_path', 'disk'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'disk' => null, 'storage_path' => null, 'download_name' => null, 'size' => null,
        'file_count' => null, 'expires_at' => null, 'downloaded_at' => null,
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'downloaded_at' => 'datetime', 'size' => 'integer', 'file_count' => 'integer'];
    }

    /** @return BelongsTo<Applicant, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->expires_at?->isFuture();
    }
}
