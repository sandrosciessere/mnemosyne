<?php

namespace App\Services\Ingestion;

use App\Enums\IngestionEventType;
use App\Enums\IngestionStage;
use App\Models\IngestionEvent;
use App\Models\IngestionRun;
use App\Services\Library\LibraryStorage;
use Illuminate\Support\Facades\DB;

/**
 * Bridges worker-delegated stages (validate/parse/normalize/structure)
 * to the internal worker API and applies their domain side effects after
 * the executor has classified the verdict (so admin overrides work).
 */
class WorkerStage
{
    public function __construct(
        private readonly WorkerClient $client,
        private readonly LibraryStorage $storage,
    ) {}

    public function run(IngestionRun $run, IngestionStage $stage): StageResult
    {
        $asset = $run->asset;

        if ($asset === null) {
            return StageResult::failed('ASSET_MISSING', 'Run has no asset — hash stage must complete first.');
        }

        $sourcePath = $asset->storage_path ?? $run->submission->incoming_path;

        if ($sourcePath === null || ! $this->storage->disk()->exists($sourcePath)) {
            return StageResult::failed('SOURCE_FILE_MISSING', 'EPUB file is not available for processing.');
        }

        $envelope = $this->client->stage($stage->value, [
            'asset_ref' => $asset->public_id,
            'relative_path' => $sourcePath,
            'artifact_dir' => $asset->artifactDir($run->pipeline_version),
            'pipeline_version' => $run->pipeline_version,
            'source_sha256' => $asset->sha256,
            'correlation_id' => $run->correlation_id,
        ]);

        return $this->toResult($envelope, $stage);
    }

    /**
     * Domain writes for a stage that has been classified successful
     * (including "successful because an admin overrode its reviewable
     * issues"). Called by the executor, inside its completion path.
     */
    public function applySideEffects(IngestionRun $run, IngestionStage $stage, StageResult $result): void
    {
        if ($stage === IngestionStage::Hash || $run->asset === null) {
            return;
        }

        match ($stage) {
            IngestionStage::Validate => $this->afterValidate($run, $result),
            IngestionStage::Parse => $this->afterParse($run, $result),
            IngestionStage::Normalize => $this->afterNormalize($run, $result),
            IngestionStage::Structure => $this->afterStructure($run, $result),
            default => null,
        };
    }

    private function afterValidate(IngestionRun $run, StageResult $result): void
    {
        $asset = $run->asset;
        $submission = $run->submission;
        $summary = $result->summary;

        // Promote to immutable content-addressed storage exactly once the
        // file is known to be safe. Idempotent on retries.
        if ($asset->storage_path === null && $submission->incoming_path !== null) {
            $storagePath = $this->storage->promoteToOriginal($submission->incoming_path, $asset->sha256);

            DB::transaction(function () use ($asset, $run, $storagePath) {
                $asset->forceFill(['storage_path' => $storagePath])->save();
                IngestionEvent::record(IngestionEventType::AssetPromoted, run: $run, payload: [
                    'storage_path' => $storagePath,
                ]);
            });
        }

        $asset->forceFill([
            'validation_status' => $result->status === 'passed' ? 'passed' : 'passed_with_warnings',
            'epub_version' => $summary['epub_version'] ?? $asset->epub_version,
            'uncompressed_size_bytes' => $summary['zip']['total_uncompressed_bytes']
                ?? $asset->uncompressed_size_bytes,
        ])->save();
    }

    private function afterParse(IngestionRun $run, StageResult $result): void
    {
        $metadata = $result->summary['metadata'] ?? null;

        if (is_array($metadata)) {
            $run->asset->forceFill(['extracted_metadata' => $metadata])->save();
        }
    }

    private function afterNormalize(IngestionRun $run, StageResult $result): void
    {
        $summary = $result->summary;
        $asset = $run->asset;

        $asset->forceFill([
            'structure_summary' => array_merge($asset->structure_summary ?? [], array_filter([
                'spine_items' => $summary['spine_documents'] ?? null,
                'nodes' => $summary['nodes'] ?? null,
                'text_chars' => $summary['chars'] ?? null,
                'image_only_documents' => $summary['image_only_documents'] ?? null,
            ], fn ($value) => $value !== null)),
        ])->save();
    }

    private function afterStructure(IngestionRun $run, StageResult $result): void
    {
        $summary = $result->summary;
        $asset = $run->asset;

        $counts = $summary['counts'] ?? [];

        $asset->forceFill([
            'content_sha256' => $summary['content_sha256'] ?? $asset->content_sha256,
            'content_fingerprint_version' => $summary['fingerprint_version'] ?? '1',
            'structure_summary' => array_merge($asset->structure_summary ?? [], array_filter([
                'sections' => $counts['sections'] ?? null,
                'toc_entries' => $counts['toc_entries'] ?? null,
                'nodes' => $counts['nodes'] ?? null,
                'text_chars' => $counts['chars'] ?? null,
            ], fn ($value) => $value !== null)),
        ])->save();
    }

    private function toResult(array $envelope, IngestionStage $stage): StageResult
    {
        $status = (string) ($envelope['status'] ?? 'failed');
        $issues = array_values(array_map(fn ($issue) => [
            'code' => (string) ($issue['code'] ?? 'UNKNOWN'),
            'severity' => (string) ($issue['severity'] ?? 'warning'),
            'message' => (string) ($issue['message'] ?? ''),
            'overrideable' => (bool) ($issue['overrideable'] ?? false),
            'details' => $issue['details'] ?? [],
            'stage' => $stage->value,
        ], $envelope['issues'] ?? []));

        $summary = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
        $handlerVersion = $envelope['handler_version'] ?? null;

        return match ($status) {
            'passed', 'passed_with_warnings' => new StageResult(
                $status,
                $issues,
                $summary,
                $handlerVersion,
            ),
            'needs_review' => new StageResult('needs_review', $issues, $summary, $handlerVersion),
            default => StageResult::failed(
                $this->primaryErrorCode($issues) ?? 'EPUB_PROCESSING_FAILED',
                $this->primaryErrorMessage($issues) ?? 'The worker could not process this EPUB.',
                retryable: false,
                issues: $issues,
                handlerVersion: $handlerVersion,
            ),
        };
    }

    private function primaryErrorCode(array $issues): ?string
    {
        foreach (['hard_block', 'reviewable', 'warning'] as $severity) {
            foreach ($issues as $issue) {
                if ($issue['severity'] === $severity) {
                    return $issue['code'];
                }
            }
        }

        return $issues[0]['code'] ?? null;
    }

    private function primaryErrorMessage(array $issues): ?string
    {
        foreach ($issues as $issue) {
            if ($issue['message'] !== '') {
                return $issue['message'];
            }
        }

        return null;
    }
}
