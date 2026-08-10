<?php

namespace App\Services\Ingestion;

use App\Enums\AssetIngestionStatus;
use App\Enums\IngestionEventType;
use App\Enums\IngestionRunStatus;
use App\Models\BookAsset;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Library\LibraryStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1 — streaming SHA-256 of the source file and exact deduplication.
 * Runs fully inside Laravel (no worker round-trip for a hash).
 *
 * Exact-duplicate dispositions (returned as summary['duplicate_disposition']
 * and finalized by the executor / state machine):
 *  - new          → first time this content is seen; pipeline continues.
 *  - adopt        → an orphaned asset (no active run, not yet ready) is
 *                   adopted and the pipeline continues.
 *  - ready        → the existing asset is already ready(-with-warnings);
 *                   the run short-circuits to succeeded and the submitter
 *                   is granted access. No reprocessing.
 *  - unsupported  → the existing asset was marked unsupported by an admin;
 *                   the duplicate run mirrors that decision (skipped). The
 *                   admin decision is never silently reversed.
 *  - failed       → the existing asset failed ingestion; the duplicate run
 *                   mirrors the failure rather than claiming success.
 *  - waiting       → the existing asset is still being processed by another
 *                   run; this run parks (queued, waiting_on_asset_id set)
 *                   and is finalized when the owning run reaches a terminal
 *                   outcome. It never claims success prematurely.
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

        // First-seen fast path with an explicit race handler: two concurrent
        // first uploads of the same bytes both find no row and both INSERT.
        // The unique sha256 constraint lets exactly one win; the loser's
        // insert aborts its own transaction, and we converge on the winner's
        // asset through the duplicate path — never a STAGE_CRASH.
        if (BookAsset::query()->where('sha256', $sha256)->doesntExist()) {
            try {
                return DB::transaction(function () use ($run, $submission, $sha256, $sizeBytes) {
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
                        ['sha256' => $sha256, 'size_bytes' => $sizeBytes, 'duplicate' => false, 'duplicate_disposition' => 'new'],
                        StageExecutor::HANDLER_VERSIONS['hash'],
                    );
                });
            } catch (UniqueConstraintViolationException) {
                // Lost the insert race: the winner committed the asset. Fall
                // through to the duplicate path against the now-present row.
            }
        }

        return $this->handleExactDuplicate($run, $submission, $sha256, $sizeBytes);
    }

    private function handleExactDuplicate(IngestionRun $run, $submission, string $sha256, int $sizeBytes): StageResult
    {
        return DB::transaction(function () use ($run, $submission, $sha256) {
            $existing = BookAsset::query()->where('sha256', $sha256)->lockForUpdate()->firstOrFail();

            // Never store a second physical copy. Keep the submission and its
            // provenance, and record the exact-duplicate signal.
            $submission->forceFill(['book_asset_id' => $existing->id, 'is_exact_duplicate' => true])->save();

            IngestionEvent::record(IngestionEventType::DuplicateExactDetected, $submission, $run, payload: [
                'sha256' => $sha256,
                'existing_asset' => $existing->public_id,
                'existing_status' => $existing->ingestion_status->value,
            ]);

            // Another run still owns this asset's pipeline: wait for its
            // outcome instead of guessing. Parked queued; a waiting run never
            // owns the asset (book_asset_id stays null) so the one-active-run
            // -per-asset index is never violated.
            $ownedByActiveRun = $existing->runs()
                ->where('id', '!=', $run->id)
                ->whereIn('status', array_map(fn ($case) => $case->value, IngestionRunStatus::activeCases()))
                ->exists();

            $status = $existing->ingestion_status;

            if ($status->isReadyForEnrichment()) {
                // Fully processed already: short-circuit to success + grant.
                $run->forceFill(['book_asset_id' => $existing->id])->save();

                return $this->duplicateResult($sha256, 'ready', $existing);
            }

            if ($status === AssetIngestionStatus::Unsupported) {
                // An admin marked this content unsupported. Mirror that
                // decision; never silently reprocess and revive it.
                $run->forceFill(['book_asset_id' => $existing->id])->save();

                return $this->duplicateResult($sha256, 'unsupported', $existing);
            }

            if ($status === AssetIngestionStatus::Failed && ! $ownedByActiveRun) {
                // The content failed ingestion and nothing is retrying it:
                // mirror the failure rather than claim success.
                $run->forceFill(['book_asset_id' => $existing->id])->save();

                return $this->duplicateResult($sha256, 'failed', $existing);
            }

            if ($ownedByActiveRun) {
                // Owner still in flight: park and wait for the terminal
                // outcome. Do NOT set book_asset_id (would create a second
                // active run on the asset).
                $run->forceFill(['waiting_on_asset_id' => $existing->id])->save();

                return $this->duplicateResult($sha256, 'waiting', $existing);
            }

            // Orphaned asset (e.g. a prior run was cancelled and left it
            // pending/processing) with nothing working on it: adopt and
            // continue the pipeline from the current run.
            $run->forceFill(['book_asset_id' => $existing->id])->save();
            $existing->forceFill(['ingestion_status' => 'processing'])->save();

            return $this->duplicateResult($sha256, 'adopt', $existing);
        });
    }

    private function duplicateResult(string $sha256, string $disposition, BookAsset $existing): StageResult
    {
        return StageResult::passed([
            'sha256' => $sha256,
            'duplicate' => true,
            'duplicate_disposition' => $disposition,
            'existing_asset' => $existing->public_id,
        ], StageExecutor::HANDLER_VERSIONS['hash']);
    }
}
