<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_template_id
 * @property string $field_key
 * @property string $label
 * @property string|null $help_text
 * @property string $type
 * @property array<string, mixed>|null $config
 * @property bool $required
 * @property bool $sensitive
 * @property int $sort_order
 */
class DocumentField extends Model
{
    /** @var list<string> */
    protected $fillable = ['field_key', 'label', 'help_text', 'type', 'config', 'required', 'sensitive', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = ['help_text' => null, 'config' => null, 'required' => false, 'sensitive' => false, 'sort_order' => 100];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'required' => 'boolean',
            'sensitive' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
