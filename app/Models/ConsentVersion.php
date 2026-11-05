<?php

namespace App\Models;

use Database\Factories\ConsentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Versioned disclosure/consent text. Content is immutable once created:
 * changes are made by creating a new version.
 *
 * @property int $id
 * @property string $version
 * @property string $title
 * @property string $body
 * @property string $body_sha256
 * @property bool $active
 * @property Carbon|null $effective_at
 * @property int|null $created_by
 */
class ConsentVersion extends Model
{
    /** @use HasFactory<ConsentVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['version', 'title', 'body'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => false, 'effective_at' => null, 'created_by' => null];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ConsentVersion $version) {
            if ($version->exists && $version->isDirty(['version', 'title', 'body'])) {
                throw new LogicException('Consent text is immutable; create a new version instead.');
            }

            $version->body_sha256 = hash('sha256', $version->body);
        });
    }

    public static function current(): ?self
    {
        return static::where('active', true)->latest('effective_at')->latest('id')->first();
    }

    /** @return HasMany<ApplicantConsent, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(ApplicantConsent::class);
    }
}
