<?php

namespace App\Http\Requests\Hr;

use App\Models\Applicant;
use Illuminate\Foundation\Http\FormRequest;

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
            'send_invitation' => ['sometimes', 'boolean'],
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
     * @return array{first_name: string, last_name: string, email: string}
     */
    public function applicantData(): array
    {
        return $this->safe()->only(['first_name', 'last_name', 'email']);
    }
}
