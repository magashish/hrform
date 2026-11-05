<?php

namespace App\Services\Documents;

use App\Models\DocumentField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Closed set of form field types. HR-entered configuration is validated per
 * type and only known keys are kept: no arbitrary rules, regexes or HTML.
 */
class FieldTypeRegistry
{
    public const TYPES = [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'date' => 'Date',
        'number' => 'Number',
        'select' => 'Drop-down list',
        'radio' => 'Single choice (radio buttons)',
        'checkbox' => 'Yes / no checkbox',
        'ssn' => 'Social Security number',
        'attestation' => 'Attestation (must be ticked)',
    ];

    /**
     * Validates and normalizes raw HR input into a stored config array.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalizeConfig(string $type, array $input): array
    {
        $rules = match ($type) {
            'text' => ['max_length' => ['nullable', 'integer', 'min:1', 'max:500']],
            'textarea' => ['max_length' => ['nullable', 'integer', 'min:1', 'max:5000']],
            'number' => [
                'min' => ['nullable', 'numeric'],
                'max' => ['nullable', 'numeric'],
                'integer_only' => ['nullable', 'boolean'],
            ],
            'date' => [
                'not_future' => ['nullable', 'boolean'],
                'not_past' => ['nullable', 'boolean'],
            ],
            'select', 'radio' => ['options' => ['required', 'string', 'max:10000']],
            'attestation' => ['statement' => ['required', 'string', 'max:2000']],
            default => [],
        };

        $data = Validator::make($input, $rules)->validate();
        $config = [];

        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'options') {
                $options = array_values(array_unique(array_filter(
                    array_map(fn ($o) => mb_substr(trim($o), 0, 200), preg_split('/\R/', (string) $value) ?: []),
                    fn ($o) => $o !== '',
                )));

                if ($options === [] || count($options) > 100) {
                    throw ValidationException::withMessages(['options' => 'Enter between 1 and 100 options, one per line.']);
                }
                $config['options'] = $options;
            } elseif (in_array($key, ['integer_only', 'not_future', 'not_past'], true)) {
                $config[$key] = (bool) $value;
            } elseif (in_array($key, ['min', 'max'], true)) {
                $config[$key] = (float) $value;
            } elseif ($key === 'max_length') {
                $config[$key] = (int) $value;
            } else {
                $config[$key] = (string) $value;
            }
        }

        return $config;
    }

    /**
     * Server-side rules for one field. When $submitting is false (draft
     * save), required-ness is not enforced but types still are.
     *
     * @return array<int, mixed>
     */
    public function rulesFor(DocumentField $field, bool $submitting): array
    {
        $required = $submitting && $field->required;
        $base = [$required ? 'required' : 'nullable'];

        return array_merge($base, match ($field->type) {
            'text' => ['string', 'max:'.(int) $field->configValue('max_length', 255)],
            'textarea' => ['string', 'max:'.(int) $field->configValue('max_length', 2000)],
            'email' => ['string', 'max:254', 'email:strict'],
            'phone' => ['string', 'max:30', 'regex:/^[0-9+().\-\s]{7,30}$/'],
            'date' => array_filter([
                'date_format:Y-m-d',
                $field->configValue('not_future') ? 'before_or_equal:today' : null,
                $field->configValue('not_past') ? 'after_or_equal:today' : null,
            ]),
            'number' => array_filter([
                $field->configValue('integer_only') ? 'integer' : 'numeric',
                $field->configValue('min') !== null ? 'min:'.$field->configValue('min') : null,
                $field->configValue('max') !== null ? 'max:'.$field->configValue('max') : null,
            ]),
            'select', 'radio' => ['string', Rule::in($field->configValue('options', []))],
            'checkbox' => ['boolean'],
            'ssn' => ['string', 'regex:/^(?!000|666|9\d\d)\d{3}-?(?!00)\d{2}-?(?!0000)\d{4}$/'],
            'attestation' => $submitting ? ['accepted'] : ['boolean'],
            default => ['prohibited'],
        });
    }

    /**
     * Normalizes a validated value for storage.
     */
    public function normalizeValue(DocumentField $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return in_array($field->type, ['checkbox', 'attestation'], true) ? false : null;
        }

        return match ($field->type) {
            'checkbox', 'attestation' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'ssn' => preg_replace('/^(\d{3})-?(\d{2})-?(\d{4})$/', '$1-$2-$3', (string) $value),
            'number' => is_numeric($value) ? $value + 0 : null,
            default => trim((string) $value),
        };
    }

    /**
     * Display value; sensitive values are masked unless $reveal is true.
     */
    public function display(DocumentField $field, mixed $value, bool $reveal = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (in_array($field->type, ['checkbox', 'attestation'], true)) {
            return $value ? 'Yes' : 'No';
        }

        if (! $reveal && ($field->type === 'ssn' || $field->sensitive)) {
            $str = (string) $value;

            return $field->type === 'ssn' ? '***-**-'.substr($str, -4) : str_repeat('•', min(8, mb_strlen($str)));
        }

        return (string) $value;
    }
}
