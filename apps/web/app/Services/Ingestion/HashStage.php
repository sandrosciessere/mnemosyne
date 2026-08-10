<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionEventType;
use App\Models\BookAsset;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Library\LibraryStorage;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1 — streaming SHA-256 of the source file and exact deduplication.
 * Runs fully inside Laravel (no worker round-trip for a hash).
 */
class HashStage
{
    public function __construct(private readonly LibraryStorage $storage) {}

    public function run(IngestionRun $run): StageResult
    {
        // Retry after a crash that already linked the asset: nothing to redo.
        if ($run->book_asset_id !== null) {
            return StageResult::passed(
                ['sha256' => $run->asset->sha256, 'already_hashed' => true],
                StageExecutor::HANDLER_VERSIONS['hash'],
            );
        }

        $submission = $run->submission;

        if ($submission->incoming_path === null || ! $this->storage->disk()->exists($submission->incoming_path)) {
            return StageResult::failed('INCOMING_FILE_MISSING', 'Source file is not available for hashing.');
        }

        $absolute = $this->storage->absolutePath($submission->incoming_path);
        $sha256 = hash_file('sha256', $absolute);

        if ($sha256 === false) {
            return StageResult::failed('HASH_FAILED', 'Could not hash the source file.', retryable: true);
        }

        $sizeBytes = (int) ($this->storage->disk()->size($submission->incoming_path) ?: 0);

        return DB::transaction(function () use ($run, $submission, $sha256, $sizeBytes) {
            $existing = BookAsset::query()->where('sha256', $sha256)->lockForUpdate()->first();

            if ($existing === null) {
                $asset = new BookAsset;
                $asset->forceFill([
                    'sha256' => $sha256,
                    'original_filename' => $submission->original_filename,
                    'size_bytes' => $sizeBytes,
                    'ingestion_status' => 'processing',
                ])->save();

                $run->forceFill(['book_asset_id' => $asset->id])->save();
                $submission->forceFill(['book_asset_id' => $asset->id, 'upload_size_bytes' => $sizeBytes])->save();

                return StageResult::passed(
                    ['sha256' => $sha256, 'size_bytes' => $sizeBytes, 'duplicate' => false],
                    StageExecutor::HANDLER_VERSIONS['hash'],
                );
            }

            // Exact duplicate: never store a second physical copy, keep the
            // submission + provenance, link the submitter to the existing
            // asset.
            $submission->forceFill(['book_asset_id' => $existing->id, 'is_exact_duplicate' => true])->save();

            IngestionEvent::record(IngestionEventType::DuplicateExactDetected, $submission, $run, payload: [
                'sha256' => $sha256,
                'existing_asset' => $existing->public_id,
                'existing_status' => $existing->ingestion_status->value,
            ]);

            $hasActiveRun = $existing->runs()
                ->whereIn('status', ['queued', 'running', 'needs_review'])
                ->exists();

            if ($existing->ingestion_status->value === 'ready_for_enrichment' || $hasActiveRun) {
                // Fully processed (or being processed by another run):
                // do not reprocess. The run short-circuits to success.
                $run->forceFill($hasActiveRun ? [] : ['book_asset_id' => $existing->id])->save();

                if ($submission->user_id !== null) {
                    app(RunStateMachine::class)->grantSubmitterAccessTo($submission->user_id, $existing->id);
                }

                return StageResult::passed([
                    'sha256' => $sha256,
                    'duplicate' => true,
                    'duplicate_of_ready_asset' => true,
                    'existing_asset' => $existing->public_id,
                ], StageExecutor::HANDLER_VERSIONS['hash']);
            }

            // Same file exists but was never fully processed and nothing is
            // working on it: adopt it and continue the pipeline.
            $run->forceFill(['book_asset_id' => $existing->id])->save();
            $existing->forceFill(['ingestion_status' => 'processing'])->save();

            return StageResult::passed([
                'sha256' => $sha256,
                'duplicate' => true,
                'duplicate_of_ready_asset' => false,
                'existing_asset' => $existing->public_id,
            ], StageExecutor::HANDLER_VERSIONS['hash']);
        });
    }
}
