<?php

namespace Database\Factories;

use App\Enums\IngestionPriority;
use App\Enums\SubmissionSourceType;
use App\Enums\SubmissionStatus;
use App\Models\BookSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookSubmission>
 */
class BookSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_type' => SubmissionSourceType::Upload,
            'original_filename' => fake()->slug(3).'.epub',
            'status' => SubmissionStatus::PendingApproval,
            'priority' => IngestionPriority::Normal,
            'upload_size_bytes' => fake()->numberBetween(10_000, 5_000_000),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function filesystem(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'source_type' => SubmissionSourceType::Filesystem,
            'source_reference' => [
                'root' => 'main-library',
                'relative_path' => 'Author Name/Book Title/book.epub',
                'author_hint' => 'Author Name',
                'title_hint' => 'Book Title',
            ],
        ]);
    }
}
