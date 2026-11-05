<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $applicant_id
 * @property string $token_hash
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $first_used_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property string $delivery_status
 * @property Carbon|null $sent_at
 * @property int $failed_attempts
 * @property int|null $created_by
 */
class ApplicantInvitation extends Model
{
    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'first_used_at' => null,
        'last_used_at' => null,
        'revoked_at' => null,
        'revoke_reason' => null,
        'delivery_status' => self::DELIVERY_QUEUED,
        'sent_at' => null,
        'failed_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_used_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Applicant, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'Revoked',
            $this->isExpired() => 'Expired',
            default => 'Active',
        };
    }
}
