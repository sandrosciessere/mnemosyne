<?php

namespace App\Services\Retrieval;

use App\Exceptions\Library\InvalidTransitionException;
use App\Exceptions\Library\WorkerUnavailableException;
use App\Jobs\IndexAssetForRetrievalJob;
use App\Models\BookAsset;
use App\Models\RetrievalAssetState;
use App\Models\RetrievalChunk;
use App\Models\RetrievalEmbedding;
use App\Models\RetrievalEvidenceSpan;
use App\Models\RetrievalGeneration;
use App\Services\Library\LibraryStorage;
use App\Services\Retrieval\Embedding\EmbeddingProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;

/**
 * Per-asset retrieval indexing: verify source identity → deterministic
 * chunking → batched embeddings → ready. Durable, resumable and
 * idempotent: a crash mid-embedding resumes exactly where it stopped;
 * re-running a ready asset with an unchanged source is a no-op.
 * Retrieval failures NEVER touch the Milestone 1 ingestion state.
 */
class RetrievalIndexer
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Chunker $chunker,
        private readonly LibraryStorage $storage,
        private readonly EmbeddingProviderFactory $embeddings,
    ) {}

    /** Hook for newly ready assets: enqueue into the active generation. */
    public function enqueueForActiveGeneration(BookAsset $asset): void
    {
        $generation = RetrievalGeneration::active();

        if ($generation === null || ! $asset->ingestion_status->isReadyForEnrichment()) {
            return;
        }

        IndexAssetForRetrievalJob::dispatch($generation->id, $asset->id)
            ->onConnection(config('mnemosyne.ingestion.queue_connection'))
            ->onQueue(config('mnemosyne.retrieval.queue'));
    }

    public function indexAsset(RetrievalGeneration $generation, BookAsset $asset): RetrievalAssetState
    {
        $state = $this->stateFor($generation, $asset);

        // Idempotent no-op: already ready for this exact source identity.
        if ($state->status === 'ready'
            && $state->source_content_sha256 === $asset->content_sha256) {
            return $state;
        }

        if (! $asset->ingestion_status->isReadyForEnrichment()) {
            return $this->fail($state, 'ASSET_NOT_READY', 'Asset is not ready for enrichment.', permanent: true);
        }

        try {
            $this->verifySourceIdentity($state, $asset);

            if ($state->status !== 'embedding' || $state->chunk_count === 0) {
                $this->chunkAsset($generation, $asset, $state);
            }

            $this->embedMissing($generation, $asset, $state);

            $state->forceFill([
                'status' => 'ready',
                'finished_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            Log::info('retrieval.asset_ready', [
                'generation' => $generation->public_id,
                'asset' => $asset->public_id,
                'chunks' => $state->chunk_count,
            ]);

            return $state;
        } catch (WorkerUnavailableException $exception) {
            return $this->fail($state, 'WORKER_UNAVAILABLE', $exception->getMessage(), permanent: false);
        } catch (InvalidTransitionException $exception) {
            return $this->fail($state, $exception->errorCode, $exception->getMessage(), permanent: true);
        }
    }

    private function stateFor(RetrievalGeneration $generation, BookAsset $asset): RetrievalAssetState
    {
        return DB::transaction(function () use ($generation, $asset) {
            $state = RetrievalAssetState::query()
                ->where('retrieval_generation_id', $generation->id)
                ->where('book_asset_id', $asset->id)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                $state = new RetrievalAssetState;
                $state->forceFill([
                    'retrieval_generation_id' => $generation->id,
                    'book_asset_id' => $asset->id,
                    'status' => 'pending',
                    'source_content_sha256' => (string) $asset->content_sha256,
                    'source_pipeline_version' => (string) $asset->pipeline_version,
                ])->save();
            }

            $state->forceFill([
                'attempts' => $state->attempts + 1,
                'started_at' => $state->started_at ?? now(),
            ])->save();

            return $state;
        });
    }

    /**
     * The chunk set is only valid for the exact source it was built from:
     * canonical.txt must hash to the asset's content fingerprint, and the
     * state must have been created for that same fingerprint.
     */
    private function verifySourceIdentity(RetrievalAssetState $state, BookAsset $asset): void
    {
        if ($asset->content_sha256 === null || $asset->pipeline_version === null) {
            throw new InvalidTransitionException('SOURCE_ARTIFACTS_MISSING', 'Asset has no structural artifacts.');
        }

        if ($state->source_content_sha256 !== $asset->content_sha256) {
            throw new InvalidTransitionException(
                'SOURCE_HASH_MISMATCH',
                'Asset source changed since this retrieval run was created.',
            );
        }

        $canonicalPath = $asset->artifactDir($asset->pipeline_version).'/canonical.txt';

        if (! $this->storage->disk()->exists($canonicalPath)) {
            throw new InvalidTransitionException('SOURCE_ARTIFACTS_MISSING', 'canonical.txt is missing.');
        }

        try {
            $stream = $this->storage->disk()->readStream($canonicalPath);
        } catch (FilesystemException $exception) {
            throw new InvalidTransitionException('SOURCE_ARTIFACTS_MISSING', 'canonical.txt is unreadable.');
        }

        if ($stream === null) {
            throw new InvalidTransitionException('SOURCE_ARTIFACTS_MISSING', 'canonical.txt is missing.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $actual = hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($actual !== $asset->content_sha256) {
            throw new InvalidTransitionException(
                'SOURCE_HASH_MISMATCH',
                'canonical.txt does not match the asset content fingerprint.',
            );
        }
    }

    private function chunkAsset(RetrievalGeneration $generation, BookAsset $asset, RetrievalAssetState $state): void
    {
        $state->forceFill(['status' => 'chunking'])->save();

        $result = $this->chunker->chunkAsset($asset, $generation->config['chunker']['config']);

        DB::transaction(function () use ($generation, $asset, $state, $result) {
            // Deterministic rebuild: replacing an incomplete chunk set with
            // an identical one (cascade removes spans + embeddings of the
            // partial run — embeddings are re-created idempotently after).
            RetrievalChunk::query()
                ->where('retrieval_generation_id', $generation->id)
                ->where('book_asset_id', $asset->id)
                ->delete();

            foreach ($result['drafts'] as $draft) {
                $chunk = new RetrievalChunk;
                $chunk->forceFill([
                    'public_id' => strtolower((string) Str::ulid()),
                    'retrieval_generation_id' => $generation->id,
                    'book_asset_id' => $asset->id,
                    'ordinal' => $draft->ordinal,
                    'heading_path' => $draft->headingPath ?: null,
                    'heading_text' => $draft->headingPath === []
                        ? null
                        : mb_substr(implode(' › ', $draft->headingPath), 0, 1000),
                    'spine_index' => $draft->spineIndex,
                    'source_text' => $draft->sourceText,
                    'char_count' => $draft->charCount(),
                    'token_estimate' => (int) ceil($draft->charCount() / 4),
                    'content_sha256' => $draft->contentSha256,
                    'canonical_start' => $draft->canonicalStart,
                    'canonical_end' => $draft->canonicalEnd,
                    'overlap_prefix_chars' => $draft->overlapPrefixChars,
                    'source_content_sha256' => (string) $asset->content_sha256,
                ])->save();

                $rows = array_map(fn ($span) => [
                    'retrieval_chunk_id' => $chunk->id,
                    'book_asset_id' => $asset->id,
                    'span_ordinal' => $span['span_ordinal'],
                    'source_node_id' => $span['source_node_id'],
                    'spine_index' => $span['spine_index'],
                    'href' => mb_substr($span['href'], 0, 1000),
                    'fragment' => $span['fragment'] !== null ? mb_substr($span['fragment'], 0, 250) : null,
                    'node_type' => $span['node_type'],
                    'heading_path' => json_encode($span['heading_path'], JSON_UNESCAPED_UNICODE),
                    'canonical_start' => $span['canonical_start'],
                    'canonical_end' => $span['canonical_end'],
                    'utf16_start' => $span['utf16_start'],
                    'utf16_end' => $span['utf16_end'],
                    'chunk_start' => $span['chunk_start'],
                    'chunk_end' => $span['chunk_end'],
                    'source_hash' => $span['source_hash'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $draft->spans);

                foreach (array_chunk($rows, 200) as $batch) {
                    RetrievalEvidenceSpan::query()->insert($batch);
                }
            }

            $state->forceFill([
                'status' => 'embedding',
                'chunk_count' => count($result['drafts']),
                'embedded_count' => 0,
            ])->save();
        });
    }

    /** Embeds every chunk still missing an embedding row (resume-safe). */
    private function embedMissing(RetrievalGeneration $generation, BookAsset $asset, RetrievalAssetState $state): void
    {
        $state->forceFill(['status' => 'embedding'])->save();

        $provider = $this->embeddings->forGeneration($generation);
        $batchSize = (int) ($generation->config['embedding']['batch_size'] ?? 32);

        while (true) {
            $chunks = RetrievalChunk::query()
                ->where('retrieval_generation_id', $generation->id)
                ->where('book_asset_id', $asset->id)
                ->whereDoesntHave('embedding')
                ->orderBy('ordinal')
                ->limit($batchSize)
                ->get();

            if ($chunks->isEmpty()) {
                break;
            }

            $texts = $chunks->map(fn ($chunk) => $this->embeddingText($chunk))->all();
            $vectors = $provider->embedDocuments($texts);

            DB::transaction(function () use ($chunks, $texts, $vectors, $generation, $asset, $provider, $state) {
                foreach ($chunks as $index => $chunk) {
                    $embedding = new RetrievalEmbedding;
                    $embedding->forceFill([
                        'retrieval_chunk_id' => $chunk->id,
                        'retrieval_generation_id' => $generation->id,
                        'book_asset_id' => $asset->id,
                        'model_key' => $provider->modelIdentity()['model_key'],
                        'dims' => $provider->dimensions(),
                        'embedding_text_sha256' => hash('sha256', $texts[$index]),
                        'embedding' => '['.implode(',', $vectors[$index]).']',
                    ])->save();
                }

                $state->forceFill([
                    'embedded_count' => $state->embedded_count + $chunks->count(),
                ])->save();
            });
        }

        $embedded = RetrievalEmbedding::query()
            ->where('retrieval_generation_id', $generation->id)
            ->where('book_asset_id', $asset->id)
            ->count();

        $state->forceFill(['embedded_count' => $embedded])->save();

        if ($embedded !== $state->chunk_count) {
            throw new InvalidTransitionException(
                'EMBEDDING_INCOMPLETE',
                sprintf('%d of %d chunks embedded.', $embedded, $state->chunk_count),
            );
        }
    }

    /**
     * Embedding input = heading context + source text. Context improves
     * dense recall but is NEVER quotable source evidence (the chunk's
     * source_text and spans remain the only citation-bearing data).
     */
    public function embeddingText(RetrievalChunk $chunk): string
    {
        $heading = $chunk->heading_text;

        return $heading === null || $heading === ''
            ? $chunk->source_text
            : $heading."\n\n".$chunk->source_text;
    }

    private function fail(RetrievalAssetState $state, string $code, string $message, bool $permanent): RetrievalAssetState
    {
        $state->forceFill([
            'status' => 'failed',
            'last_error_code' => $code,
            'last_error_message' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();

        Log::warning('retrieval.asset_failed', [
            'asset_state' => $state->id,
            'code' => $code,
            'permanent' => $permanent,
            'attempts' => $state->attempts,
        ]);

        // Transient failures requeue with backoff until MAX_ATTEMPTS.
        if (! $permanent && $state->attempts < self::MAX_ATTEMPTS) {
            IndexAssetForRetrievalJob::dispatch($state->retrieval_generation_id, $state->book_asset_id)
                ->onConnection(config('mnemosyne.ingestion.queue_connection'))
                ->onQueue(config('mnemosyne.retrieval.queue'))
                ->delay(min(600, 30 * (2 ** ($state->attempts - 1))));
        }

        return $state;
    }
}
