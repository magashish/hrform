<?php

namespace Database\Factories;

use App\Models\ConsentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentVersion>
 */
class ConsentVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'version' => 'v'.fake()->unique()->numberBetween(1, 100000),
            'title' => 'Disclosure and consent',
            'body' => fake()->paragraphs(3, true),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['active' => true, 'effective_at' => now()]);
    }
}
