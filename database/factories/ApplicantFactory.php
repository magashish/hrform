<?php

namespace Database\Factories;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'status' => ApplicantStatus::Invited,
            'created_by' => User::factory(),
        ];
    }
}
