<?php

namespace Database\Factories;

use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Work>
 */
class WorkFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'canonical_title' => $title,
            'normalized_title' => Work::normalizeTitle($title),
            'original_language' => 'en',
        ];
    }
}
