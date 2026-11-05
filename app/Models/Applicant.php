<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $applicant_ref
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property ApplicantStatus $status
 * @property int $created_by
 * @property Carbon|null $consented_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $transferred_at
 * @property int|null $transferred_by
 * @property Carbon|null $deletion_due_at
 * @property bool $legal_hold
 * @property string|null $legal_hold_reason
 */
class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'email'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'invited',
        'consented_at' => null,
        'completed_at' => null,
        'transferred_at' => null,
        'transferred_by' => null,
        'deletion_due_at' => null,
        'legal_hold' => false,
        'legal_hold_reason' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicantStatus::class,
            'consented_at' => 'datetime',
            'completed_at' => 'datetime',
            'transferred_at' => 'datetime',
            'deletion_due_at' => 'datetime',
            'legal_hold' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Applicant $applicant) {
            $applicant->public_id ??= (string) Str::uuid();
            $applicant->applicant_ref ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function transferrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    /** @return HasMany<ApplicantInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(ApplicantInvitation::class)->latest('issued_at')->latest('id');
    }

    /** @return HasOne<ApplicantInvitation, $this> */
    public function activeInvitation(): HasOne
    {
        return $this->hasOne(ApplicantInvitation::class)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latestOfMany('issued_at');
    }

    /** @return HasMany<ApplicantConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(ApplicantConsent::class);
    }

    /** @return HasMany<ApplicantDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function hasAcknowledged(ConsentVersion $version): bool
    {
        return $this->consents()->where('consent_version_id', $version->id)->exists();
    }
}
