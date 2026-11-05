<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('DOC##??'));

        return [
            'code' => $code,
            'name' => 'Document '.$code,
            'document_type_label' => $code,
            'input_mode' => 'upload',
            'signature_mode' => 'none',
            'required_by_default' => true,
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size_kb' => 10240,
            'sort_order' => 100,
            'active' => true,
        ];
    }

    public function wetSignature(): static
    {
        return $this->state(fn () => ['input_mode' => 'upload', 'signature_mode' => 'wet_signature_upload']);
    }

    public function onlineForm(): static
    {
        return $this->state(fn () => ['input_mode' => 'online_form', 'signature_mode' => 'none']);
    }

    public function acknowledgement(): static
    {
        return $this->state(fn () => ['input_mode' => 'acknowledgement', 'signature_mode' => 'none']);
    }

    /**
     * @param  list<array{key: string, label: string, required: bool}>  $requirements
     */
    public function withSupporting(array $requirements): static
    {
        return $this->state(fn () => ['supporting_requirements' => $requirements]);
    }
}
