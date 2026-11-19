<?php

namespace App\Models;

use Database\Factories\ConsentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Versioned disclosure/consent text. Content is immutable once created:
 * changes are made by creating a new version.
 *
 * @property int $id
 * @property string $notice_key
 * @property string $version
 * @property string $title
 * @property string $body
 * @property string $body_sha256
 * @property bool $active
 * @property Carbon|null $effective_at
 * @property int|null $created_by
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_sha256
 */
class ConsentVersion extends Model
{
    /** @use HasFactory<ConsentVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['notice_key', 'version', 'title', 'body'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'notice_key' => 'disclosure', 'active' => false, 'effective_at' => null, 'created_by' => null,
        'attachment_path' => null, 'attachment_name' => null, 'attachment_sha256' => null,
    ];

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
            if ($version->exists && $version->isDirty(['notice_key', 'version', 'title', 'body', 'attachment_path', 'attachment_sha256'])) {
                throw new LogicException('Consent text is immutable; create a new version instead.');
            }

            $version->body_sha256 = hash('sha256', $version->body);
        });
    }

    /**
     * Active version of one notice (default: the privacy disclosure).
     */
    public static function current(string $noticeKey = 'disclosure'): ?self
    {
        return static::where('notice_key', $noticeKey)->where('active', true)->latest('effective_at')->latest('id')->first();
    }

    /**
     * Active version of every configured notice, in the configured order.
     *
     * @return Collection<int, self>
     */
    public static function activeNotices(): Collection
    {
        $order = array_keys((array) config('portal.notices', []));
        $active = static::whereIn('notice_key', $order)->where('active', true)
            ->orderByDesc('effective_at')->orderByDesc('id')->get()->unique('notice_key');

        return $active->sortBy(fn (self $v) => array_search($v->notice_key, $order, true))->values();
    }

    public function noticeLabel(): string
    {
        return (string) (config("portal.notices.{$this->notice_key}.label") ?? $this->notice_key);
    }

    public function acknowledgementText(): string
    {
        return (string) (config("portal.notices.{$this->notice_key}.acknowledgement") ?? 'I have read and understand this notice.');
    }

    /**
     * Escaped HTML of the body with applicant placeholders filled in.
     */
    public function renderFor(?Applicant $applicant): string
    {
        $html = nl2br(e($this->body));

        return strtr($html, [
            '{first_name}' => e($applicant?->first_name ?? ''),
            '{last_name}' => e($applicant?->last_name ?? ''),
            '{full_name}' => e($applicant?->fullName() ?? ''),
            '{position_title}' => e($applicant?->position_title ?: 'the position offered'),
        ]);
    }

    /** @return HasMany<ApplicantConsent, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(ApplicantConsent::class);
    }
}
