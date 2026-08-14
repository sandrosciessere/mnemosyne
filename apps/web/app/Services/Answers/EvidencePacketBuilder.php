<?php

namespace App\Services\Answers;

use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\HybridSearchService;

/**
 * M2 retrieval results → EvidenceSpan provenance → exact canonical
 * EvidenceUnits → overlap deduplication → per-book coverage → bounded
 * EvidencePacket. Deterministic given (question, scope, policy,
 * generation, config).
 */
class EvidencePacketBuilder
{
    public function __construct(
        private readonly HybridSearchService $search,
        private readonly QueryIntentClassifier $classifier,
        private readonly QueryReformulator $reformulator,
    ) {}

    /**
     * @param  list<int>  $assetIds  ACL-resolved internal ids
     */
    public function build(
        RetrievalGeneration $generation,
        array $assetIds,
        string $question,
        RetrievalPolicy $policy,
        bool $expanded = false,
    ): EvidencePacket {
        $config = config('mnemosyne.answers.evidence');
        $unitizer = new EvidenceUnitizer((int) $config['unit_max_chars']);
        $topK = $expanded ? $policy->expansionTopK : $policy->topK;

        $diagnostics = [
            'policy' => $policy->toArray(),
            'expanded' => $expanded,
            'searches' => [],
        ];

        $candidates = [];

        // Quote-location: give the extracted literal an exact-first pass
        // so a verbatim hit dominates the packet head; the hybrid pass
        // below still runs (the quote may be paraphrased in this
        // edition).
        if ($policy->exactFirst) {
            $phrase = $this->classifier->extractQuotedPhrase($question) ?? trim($question);

            if (mb_strlen($phrase) <= (int) config('mnemosyne.retrieval.search.max_exact_phrase_chars')) {
                $exact = $this->search->search($generation, $assetIds, $phrase, 'exact', $topK, false);
                $diagnostics['searches'][] = ['mode' => 'exact', 'phrase_chars' => mb_strlen($phrase), 'results' => count($exact['results']), 'ms' => $exact['timings_ms']['total'] ?? null];
                $this->collect($candidates, $exact['results'], 'exact_first');
            } else {
                $diagnostics['searches'][] = ['mode' => 'exact', 'skipped' => 'phrase_too_long'];
            }
        }

        if ($policy->perBook && count($assetIds) >= 2) {
            // Bounded per-book evidence opportunity BEFORE global
            // selection: each selected book gets its own retrieval pass,
            // so a globally dominant book cannot monopolize the packet.
            // Relevance still rules WITHIN each book, and books with no
            // relevant evidence contribute nothing (no forced quotas).
            $perBookTopK = $expanded ? $policy->perBookTopK * 2 : $policy->perBookTopK;

            foreach ($assetIds as $assetId) {
                $result = $this->search->search($generation, [$assetId], $question, $policy->mode, $perBookTopK, $policy->rerank);
                $diagnostics['searches'][] = ['mode' => $policy->mode, 'asset_id' => $assetId, 'results' => count($result['results']), 'ms' => $result['timings_ms']['total'] ?? null];
                $diagnostics['skipped_assets'] = array_values(array_unique(array_merge(
                    $diagnostics['skipped_assets'] ?? [], $result['skipped_assets'],
                )));
                $this->collect($candidates, $result['results'], 'per_book');
            }

            // Book-fair packet assembly: units are interleaved across
            // books so budget truncation cannot silently evict a whole
            // book from the packet head.
            return $this->assemble($candidates, $unitizer, $config, $diagnostics, interleaveUnits: true);
        }

        $result = $this->search->search($generation, $assetIds, $question, $policy->mode, $topK, $policy->rerank);
        $diagnostics['searches'][] = ['mode' => $policy->mode, 'results' => count($result['results']), 'ms' => $result['timings_ms']['total'] ?? null];
        $diagnostics['skipped_assets'] = array_values(array_unique(array_merge(
            $diagnostics['skipped_assets'] ?? [], $result['skipped_assets'],
        )));
        $this->collect($candidates, $result['results'], 'main');

        return $this->assemble($candidates, $unitizer, $config, $diagnostics);
    }

    /**
     * Compound-question packet: one bounded retrieval pass PER
     * SUBQUESTION, unit streams interleaved subquestion-fairly so the
     * budget cannot silently evict a whole information need. Units are
     * tagged with the subquestion that found them; `$expandKeys` marks
     * subquestions running with the (single) focused expansion budget.
     *
     * @param  list<int>  $assetIds
     * @param  list<array{key: string, text: string}>  $subquestions
     * @param  list<string>  $expandKeys
     */
    /**
     * @param  array<string, TaskContract>  $contracts  keyed by subquestion key
     */
    public function buildForSubquestions(
        RetrievalGeneration $generation,
        array $assetIds,
        array $subquestions,
        RetrievalPolicy $policy,
        array $contracts = [],
        array $expandKeys = [],
    ): EvidencePacket {
        $config = config('mnemosyne.answers.evidence');
        $unitizer = new EvidenceUnitizer((int) $config['unit_max_chars']);

        $diagnostics = [
            'policy' => $policy->toArray(),
            'expanded' => $expandKeys !== [],
            'expansion_targets' => $expandKeys,
            'searches' => [],
        ];

        $streams = [];

        foreach ($subquestions as $subquestion) {
            $key = $subquestion['key'];
            $contract = $contracts[$key] ?? null;
            $topK = in_array($key, $expandKeys, true) ? $policy->expansionTopK : $policy->topK;
            $streams[$key] = [];

            // Bounded deterministic multi-query retrieval: up to 4
            // variants per subquestion (original, normalized content,
            // relation-aware, state-opposite). NOT an autonomous loop —
            // the variant set is fixed before any search runs.
            $variants = $contract !== null
                ? $this->reformulator->variants($contract)
                : [$subquestion['text']];

            // Quote-location subquestions get an exact-first pass on the
            // extracted literal.
            if ($contract?->taskType === TaskContract::QUOTE_LOCATION) {
                $phrase = $this->classifier->extractQuotedPhrase($subquestion['text']) ?? $subquestion['text'];

                if (mb_strlen($phrase) <= (int) config('mnemosyne.retrieval.search.max_exact_phrase_chars')) {
                    $exact = $this->search->search($generation, $assetIds, $phrase, 'exact', $topK, false);
                    $diagnostics['searches'][] = ['mode' => 'exact', 'subquestion' => $key, 'query' => mb_substr($phrase, 0, 200), 'results' => count($exact['results']), 'ms' => $exact['timings_ms']['total'] ?? null];
                    $this->collectIntoStream($streams[$key], $exact['results'], $unitizer, $key, 'exact_first');
                }
            }

            foreach ($variants as $index => $variant) {
                $result = $this->search->search($generation, $assetIds, $variant, $policy->mode, $topK, $policy->rerank);
                $diagnostics['searches'][] = [
                    'mode' => $policy->mode,
                    'subquestion' => $key,
                    'variant' => $index,
                    'query' => mb_substr($variant, 0, 200),
                    'expanded' => in_array($key, $expandKeys, true),
                    'results' => count($result['results']),
                    'ms' => $result['timings_ms']['total'] ?? null,
                ];
                $diagnostics['skipped_assets'] = array_values(array_unique(array_merge(
                    $diagnostics['skipped_assets'] ?? [], $result['skipped_assets'],
                )));

                $this->collectIntoStream($streams[$key], $result['results'], $unitizer, $key, 'subquestion');
            }
        }

        $this->expandNeighborhood($generation, $streams, $unitizer, $diagnostics);

        return $this->assembleStreams($streams, $config, $diagnostics, interleave: true);
    }

    /** @param list<array> $results HybridSearchService candidates */
    private function collectIntoStream(array &$stream, array $results, EvidenceUnitizer $unitizer, string $subquestionKey, string $branch): void
    {
        foreach ($results as $candidate) {
            foreach ($unitizer->unitsForChunk($candidate['chunk'], [
                'branch' => $branch,
                'subquestion' => $subquestionKey,
                'final_rank' => $candidate['final_rank'] ?? null,
                'components' => array_keys($candidate['components'] ?? []),
            ]) as $unit) {
                $stream[] = $unit;
            }
        }
    }

    /**
     * Bounded local-episode neighborhood: when one subquestion found
     * nothing but a sibling subquestion has strong hits, the siblings'
     * top chunks anchor a small ±window fetch of ADJACENT canonical
     * chunks (same book, same generation) for the deficient
     * subquestion. Multi-part questions about one local event often
     * share a scene: the anchor locates it, the neighborhood supplies
     * the siblings' evidence. Never scans the book; strict verification
     * still decides what any of it proves.
     *
     * @param  array<string, list<EvidenceUnit>>  $streams
     */
    private function expandNeighborhood(RetrievalGeneration $generation, array &$streams, EvidenceUnitizer $unitizer, array &$diagnostics): void
    {
        $window = (int) config('mnemosyne.answers.retrieval.neighborhood_window', 2);
        $maxAnchors = (int) config('mnemosyne.answers.retrieval.neighborhood_anchors', 2);

        $deficient = array_keys(array_filter($streams, fn ($stream) => $stream === []));

        if ($deficient === [] || $window < 1) {
            return;
        }

        // Anchor chunks: first units of the non-empty sibling streams.
        $anchorChunkIds = [];

        foreach ($streams as $stream) {
            foreach (array_slice($stream, 0, $maxAnchors) as $unit) {
                if (isset($unit->retrievalMeta['chunk_public_id'])) {
                    $anchorChunkIds[$unit->retrievalMeta['chunk_public_id']] = true;
                }
            }
        }

        $anchorChunkIds = array_slice(array_keys($anchorChunkIds), 0, $maxAnchors);

        if ($anchorChunkIds === []) {
            return;
        }

        $anchors = RetrievalChunk::query()
            ->whereIn('public_id', $anchorChunkIds)
            ->get(['id', 'public_id', 'book_asset_id', 'ordinal']);

        $fetched = 0;

        foreach ($anchors as $anchor) {
            $neighbors = RetrievalChunk::query()
                ->with(['spans', 'asset'])
                ->where('retrieval_generation_id', $generation->id)
                ->where('book_asset_id', $anchor->book_asset_id)
                ->whereBetween('ordinal', [$anchor->ordinal - $window, $anchor->ordinal + $window])
                ->where('id', '!=', $anchor->id)
                ->orderBy('ordinal')
                ->get();

            foreach ($neighbors as $chunk) {
                $fetched++;

                foreach ($deficient as $subquestionKey) {
                    foreach ($unitizer->unitsForChunk($chunk, [
                        'branch' => 'neighborhood',
                        'subquestion' => $subquestionKey,
                        'anchor_chunk' => $anchor->public_id,
                    ]) as $unit) {
                        $streams[$subquestionKey][] = $unit;
                    }
                }
            }
        }

        $diagnostics['neighborhood'] = [
            'deficient_subquestions' => $deficient,
            'anchor_chunks' => $anchorChunkIds,
            'window' => $window,
            'chunks_fetched' => $fetched,
        ];
    }

    /** @param list<array> $results HybridSearchService candidates */
    private function collect(array &$candidates, array $results, string $branch): void
    {
        foreach ($results as $candidate) {
            $candidates[] = [
                'chunk' => $candidate['chunk'],
                'meta' => [
                    'branch' => $branch,
                    'final_rank' => $candidate['final_rank'] ?? null,
                    'rrf_score' => isset($candidate['rrf_score']) ? round((float) $candidate['rrf_score'], 6) : null,
                    'components' => array_keys($candidate['components'] ?? []),
                ],
            ];
        }
    }

    /** @param list<array{chunk: mixed, meta: array}> $candidates */
    private function assemble(
        array $candidates,
        EvidenceUnitizer $unitizer,
        array $config,
        array $diagnostics,
        bool $interleaveUnits = false,
    ): EvidencePacket {
        // Unitize every candidate first (candidate rank order preserved
        // inside each stream), then choose the selection sequence.
        $streams = [];

        foreach ($candidates as $index => $candidate) {
            $streamKey = $interleaveUnits ? 'asset:'.$candidate['chunk']->book_asset_id : 'all';

            foreach ($unitizer->unitsForChunk($candidate['chunk'], $candidate['meta']) as $unit) {
                $streams[$streamKey][] = $unit;
            }
        }

        return $this->assembleStreams($streams, $config, $diagnostics, $interleaveUnits);
    }

    /**
     * Shared selection: interleave streams fairly (or flatten), then
     * dedupe + budget.
     *
     * @param  array<string, list<EvidenceUnit>>  $streams
     */
    private function assembleStreams(array $streams, array $config, array $diagnostics, bool $interleave): EvidencePacket
    {
        $sequence = [];

        if ($interleave) {
            // Round-robin one unit per stream per round (stream order =
            // scope/subquestion order): the packet head is fair by
            // construction.
            $exhausted = false;

            for ($round = 0; ! $exhausted; $round++) {
                $exhausted = true;

                foreach ($streams as $stream) {
                    if (isset($stream[$round])) {
                        $sequence[] = $stream[$round];
                        $exhausted = false;
                    }
                }
            }
        } else {
            $sequence = $streams['all'] ?? [];
        }

        $selected = [];
        $seen = [];
        $chars = 0;
        $droppedDuplicates = 0;
        $droppedBudget = 0;
        $maxUnits = (int) $config['max_units'];
        $maxChars = (int) $config['max_chars'];

        foreach ($sequence as $unit) {
            $identity = $unit->identity();

            if (isset($seen[$identity])) {
                // Same canonical evidence reached via another chunk
                // (deliberate M2 overlap): keep once, remember the extra
                // retrieval route as diagnostics.
                $selected[$seen[$identity]]->retrievalMeta['also_via'][] = $unit->retrievalMeta['chunk_public_id'] ?? null;
                $droppedDuplicates++;

                continue;
            }

            if (count($selected) >= $maxUnits || ($chars + $unit->charCount()) > $maxChars) {
                $droppedBudget++;

                continue;
            }

            $key = 'E'.(count($selected) + 1);
            $unit->key = $key;
            $seen[$identity] = $key;
            $selected[$key] = $unit;
            $chars += $unit->charCount();
        }

        $perAsset = [];
        $perSubquestion = [];

        foreach ($selected as $unit) {
            $perAsset[$unit->bookPublicId] = ($perAsset[$unit->bookPublicId] ?? 0) + 1;

            if (isset($unit->retrievalMeta['subquestion'])) {
                $sq = $unit->retrievalMeta['subquestion'];
                $perSubquestion[$sq] = ($perSubquestion[$sq] ?? 0) + 1;
            }
        }

        $stats = [
            'units' => count($selected),
            'chars' => $chars,
            'per_asset' => $perAsset,
            'dropped_duplicates' => $droppedDuplicates,
            'dropped_budget' => $droppedBudget,
        ];

        if ($perSubquestion !== []) {
            $stats['per_subquestion'] = $perSubquestion;
        }

        return new EvidencePacket(
            units: $selected,
            stats: $stats,
            diagnostics: $diagnostics,
        );
    }
}
