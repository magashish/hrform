<?php

namespace App\Services\Documents;

use App\Models\ApplicantDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores online-form answers (encrypted at rest). Only keys
 * defined on the template are accepted.
 */
class FormService
{
    public function __construct(private readonly FieldTypeRegistry $types) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(ApplicantDocument $document, array $input, bool $submitting): array
    {
        $fields = $document->template->fields;
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $rules[$field->field_key] = $this->types->rulesFor($field, $submitting);
            $attributes[$field->field_key] = $field->label;
        }

        $data = Validator::make(array_intersect_key($input, $rules), $rules, [
            'accepted' => 'You must confirm: :attribute',
            'ssn.regex' => 'Enter a valid Social Security number (e.g. 123-45-6789).',
        ], $attributes)->validate();

        $clean = [];
        foreach ($fields as $field) {
            $clean[$field->field_key] = $this->types->normalizeValue($field, $data[$field->field_key] ?? null);
        }

        return $clean;
    }

    /**
     * True when every required field has a value (used before submission).
     */
    public function isComplete(ApplicantDocument $document): bool
    {
        if (! $document->hasForm()) {
            return true;
        }

        try {
            $this->validate($document, $document->form_data ?? [], true);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
