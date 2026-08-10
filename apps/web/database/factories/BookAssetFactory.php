<?php

namespace Database\Factories;

use App\Models\BookAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookAsset>
 */
class BookAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sha256' => hash('sha256', fake()->unique()->uuid()),
            'original_filename' => fake()->slug(3).'.epub',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'mime_type' => 'application/epub+zip',
        ];
    }

    public function readyForEnrichment(): static
    {
        return $this->state(function () {
            $sha = hash('sha256', fake()->unique()->uuid());

            return [
                'sha256' => $sha,
                'content_sha256' => hash('sha256', 'content-'.$sha),
                'content_fingerprint_version' => '1',
                'storage_path' => BookAsset::originalStoragePath($sha),
                'validation_status' => 'passed',
                'ingestion_status' => 'ready_for_enrichment',
                'pipeline_version' => '1',
                'epub_version' => '3.0',
            ];
        });
    }
}
