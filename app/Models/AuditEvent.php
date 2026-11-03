<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only audit record. Create through App\Services\Audit\AuditLogger.
 *
 * @property int $id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $event
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $applicant_ref
 * @property array<string, scalar|null>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $occurred_at
 */
class AuditEvent extends Model
{
    public const ACTOR_HR = 'hr';

    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_SYSTEM = 'system';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events cannot be deleted through the model.');
        });
    }
}
