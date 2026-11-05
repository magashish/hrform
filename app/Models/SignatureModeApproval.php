<?php

namespace App\Models;

use App\Enums\SignatureMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record of who authorized a template's signature mode, and when.
 *
 * @property int $id
 * @property int $document_template_id
 * @property SignatureMode $signature_mode
 * @property string $authorized_by_name
 * @property string $approval_reference
 * @property string|null $notes
 * @property int $recorded_by
 * @property Carbon $authorized_at
 */
class SignatureModeApproval extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_mode' => SignatureMode::class,
            'authorized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
