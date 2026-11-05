<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $applicant_id
 * @property int $consent_version_id
 * @property Carbon $acknowledged_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class ApplicantConsent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /** @return BelongsTo<ConsentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ConsentVersion::class, 'consent_version_id');
    }
}
