<?php

namespace Database\Factories;

use App\Enums\IngestionPriority;
use App\Enums\IngestionRunStatus;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_submission_id' => BookSubmission::factory(),
            'pipeline_version' => '1',
            'status' => IngestionRunStatus::Queued,
            'priority' => IngestionPriority::Normal,
            'progress' => 0,
            'queued_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
