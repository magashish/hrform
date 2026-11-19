<?php

namespace App\Http\Requests\Hr;

use App\Models\Applicant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $applicant = $this->route('applicant');

        return $applicant instanceof Applicant
            ? (bool) $this->user()?->can('update', $applicant)
            : (bool) $this->user()?->can('create', Applicant::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'max:254', 'email:strict'],
            'position_title' => ['nullable', 'string', 'max:150'],
            'send_invitation' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array'],
            'stages.*' => [Rule::in(array_keys((array) config('portal.stages')))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('first_name'))),
            'last_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('last_name'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, position_title: ?string}
     */
    public function applicantData(): array
    {
        $data = $this->safe()->only(['first_name', 'last_name', 'email', 'position_title']);
        $data['position_title'] = trim((string) ($data['position_title'] ?? '')) ?: null;

        return $data;
    }
}
