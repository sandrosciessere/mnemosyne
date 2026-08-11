<?php

namespace Database\Factories;

use App\Models\Edition;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Edition>
 */
class EditionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'work_id' => Work::factory(),
            'title' => fake()->sentence(3),
            'language' => 'en',
            'publisher' => fake()->company(),
            'publication_year' => fake()->numberBetween(1900, 2026),
        ];
    }
}
