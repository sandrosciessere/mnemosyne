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

            if (mb_strlen($phrase) <= HybridSearchService::maxExactPhraseChars($generation)) {
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
        array $expansionQueries = [],
    ): EvidencePacket {
        $config = config('mnemosyne.answers.evidence');
        $unitizer = new EvidenceUnitizer((int) $config['unit_max_chars']);

        $diagnostics = [
            'policy' => $policy->toArray(),
            'expanded' => $expandKeys !== [],
            'expansion_targets' => $expandKeys,
            'expansion_queries' => $expansionQueries,
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

            // Focused expansion: the target subquestion runs its
            // dedicated expansion queries (relation perspectives, state
            // expressions, region hints) IN ADDITION to the base
            // variants — strictly more informative than a larger top-K.
            if (in_array($key, $expandKeys, true) && isset($expansionQueries[$key])) {
                foreach ($expansionQueries[$key] as $expansionQuery) {
                    $variants[] = $expansionQuery;
                }
            }

            // Quote-location subquestions get an exact-first pass on the
            // extracted literal.
            if ($contract?->taskType === TaskContract::QUOTE_LOCATION) {
                $phrase = $this->classifier->extractQuotedPhrase($subquestion['text']) ?? $subquestion['text'];

                if (mb_strlen($phrase) <= HybridSearchService::maxExactPhraseChars($generation)) {
                    $exact = $this->search->search($generation, $assetIds, $phrase, 'exact', $topK, false);
                    $diagnostics['searches'][] = ['mode' => 'exact', 'subquestion' => $key, 'query' => mb_substr($phrase, 0, 200), 'results' => count($exact['results']), 'ms' => $exact['timings_ms']['total'] ?? null];
                    $this->collectIntoStream($streams[$key], $exact['results'], $unitizer, $key, 'exact_first');
                }
            }

            // Cross-variant fusion: candidates from every variant are
            // fused by RRF over their per-variant ranks BEFORE
            // unitization, so a chunk found by several formulations
            // rises, and one variant's top chunk cannot pre-empt the
            // others by mere ordering. Each candidate remembers which
            // variants found it (diagnostics).
            $fusedByChunk = [];

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

                foreach ($result['results'] as $rank => $candidate) {
                    $chunkId = $candidate['chunk']->id;
                    $fusedByChunk[$chunkId] ??= ['chunk' => $candidate['chunk'], 'score' => 0.0, 'variants' => [], 'best_rank' => PHP_INT_MAX];
                    $fusedByChunk[$chunkId]['score'] += 1.0 / (60 + $rank + 1);
                    $fusedByChunk[$chunkId]['variants'][] = $index;
                    $fusedByChunk[$chunkId]['best_rank'] = min($fusedByChunk[$chunkId]['best_rank'], $rank + 1);
                }
            }

            uasort($fusedByChunk, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['best_rank'] <=> $b['best_rank']);

            $anchorStems = $contract !== null
                ? array_map(fn ($a) => mb_substr(mb_strtolower($a), 0, max(4, min(mb_strlen($a) - 1, 7))), $contract->anchorTerms)
                : [];
            $isExpansionTarget = in_array($key, $expandKeys, true);

            // On the expansion pass, the asked RELATION/STATE lexicon (and
            // its perspectives) also counts as anchor: the decisive
            // sentence often names the relation from the other side
            // ("la madre dei bambini era morta") without the question's
            // own words.
            $relationStems = ($isExpansionTarget && $contract !== null)
                ? $this->reformulator->relationAnchorStems($contract)
                : [];

            foreach ($fusedByChunk as $entry) {
                foreach ($unitizer->unitsForChunk($entry['chunk'], [
                    'branch' => 'subquestion',
                    'subquestion' => $key,
                    'final_rank' => $entry['best_rank'],
                    'variants' => array_values(array_unique($entry['variants'])),
                    'fused_score' => round($entry['score'], 5),
                ]) as $unit) {
                    $lower = mb_strtolower($unit->text);

                    foreach ($anchorStems as $stem) {
                        if ($stem !== '' && str_contains($lower, $stem)) {
                            $unit->retrievalMeta['anchor_hit'] = true;
                            break;
                        }
                    }

                    if ($isExpansionTarget) {
                        $unit->retrievalMeta['expansion_target'] = true;

                        // Relation/state hit: the unit names the ASKED
                        // dimension (relation lexicon, perspectives, state
                        // terms) — the signal the expansion reserve keys on.
                        foreach ($relationStems as $stem) {
                            if ($stem !== '' && str_contains($lower, $stem)) {
                                $unit->retrievalMeta['relation_hit'] = true;
                                break;
                            }
                        }
                    }

                    $streams[$key][] = $unit;
                }
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
    /**
     * Stable re-ordering INSIDE each (chunk, subquestion) group: units
     * whose text contains an anchor term of their subquestion (recorded
     * by the retrieval stage in retrievalMeta['anchor_hit']) come first;
     * relative order otherwise preserved. Cross-chunk order untouched.
     *
     * @param  list<EvidenceUnit>  $sequence
     * @return list<EvidenceUnit>
     */
    private function prioritizeAnchorUnits(array $sequence): array
    {
        $groups = [];
        $order = [];

        foreach ($sequence as $index => $unit) {
            $key = ($unit->retrievalMeta['chunk_public_id'] ?? spl_object_id($unit)).'|'.($unit->retrievalMeta['subquestion'] ?? '-');
            $groups[$key][] = $index;
            $order[] = $key;
        }

        $reordered = [];
        $emitted = [];

        foreach ($order as $key) {
            if (isset($emitted[$key])) {
                continue;
            }
            $emitted[$key] = true;
            $indexes = $groups[$key];
            $hits = array_values(array_filter($indexes, fn ($i) => ! empty($sequence[$i]->retrievalMeta['anchor_hit'])));
            $rest = array_values(array_filter($indexes, fn ($i) => empty($sequence[$i]->retrievalMeta['anchor_hit'])));

            foreach (array_merge($hits, $rest) as $i) {
                $reordered[] = $sequence[$i];
            }
        }

        return $reordered;
    }

    /**
     * Two-stage, diversity-aware packet selection.
     *
     * Stage 1 (BREADTH): walk the fused/interleaved candidate sequence
     * admitting at most `max_initial_units_per_chunk` units from any one
     * retrieved chunk and `max_initial_units_per_region` from any one
     * source region (book + spine document). A single top-ranked chunk
     * that splits into ten sentence units can no longer monopolize the
     * packet before other plausible source regions are represented.
     *
     * Stage 2 (DEPTH): the units held back in stage 1 are admitted in
     * their original relevance order until the budget is reached —
     * promising regions regain their local context once breadth exists.
     *
     * Occupancy is NOT recall: `stats.regions` reports how many distinct
     * source regions/chunks made it into the packet so diagnostics can
     * distinguish "24 units from one scene" from "24 units from twelve".
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
        $heldForDepth = 0;
        $maxUnits = (int) $config['max_units'];
        $maxChars = (int) $config['max_chars'];
        $maxPerChunk = max(1, (int) ($config['max_initial_units_per_chunk'] ?? 3));
        $maxPerRegion = max($maxPerChunk, (int) ($config['max_initial_units_per_region'] ?? 6));

        $perChunk = [];
        $perRegion = [];
        $deferred = [];
        // Breadth may take at most this share of the packet; the rest is
        // reserved for depth (held-back units of promising chunks). With
        // several subquestions round-robin breadth alone would fill the
        // budget and the decisive later sentence of a top chunk would
        // never make it in.
        // Only meaningful when several streams compete (compound
        // questions / multi-book); a single stream keeps pure relevance
        // order with per-chunk caps only.
        $breadthCap = ($interleave && count($streams) > 1)
            ? max(1, (int) floor($maxUnits * (float) ($config['breadth_share'] ?? 0.6)))
            : $maxUnits;

        $admit = function (EvidenceUnit $unit) use (&$selected, &$seen, &$chars, &$droppedDuplicates, &$droppedBudget, $maxUnits, $maxChars): bool {
            $identity = $unit->identity();

            if (isset($seen[$identity])) {
                $selected[$seen[$identity]]->retrievalMeta['also_via'][] = $unit->retrievalMeta['chunk_public_id'] ?? null;
                $droppedDuplicates++;

                return false;
            }

            if (count($selected) >= $maxUnits || ($chars + $unit->charCount()) > $maxChars) {
                $droppedBudget++;

                return false;
            }

            $key = 'E'.(count($selected) + 1);
            $unit->key = $key;
            $seen[$identity] = $key;
            $selected[$key] = $unit;
            $chars += $unit->charCount();

            return true;
        };

        // ── Stage 0: focused-expansion reservation ───────────────────
        // When this build IS the focused expansion for a subquestion,
        // that subquestion's NEW evidence must not lose the packet lottery
        // to the same units that already failed: reserve a share of the
        // budget (expansion_share, default 40%) for the target stream's
        // anchor-bearing units, admitted first in relevance order (per-
        // chunk cap still applies).
        $targetStreams = array_keys(array_filter($streams, fn ($stream) => $stream !== [] && ! empty($stream[0]->retrievalMeta['expansion_target'])));

        if ($targetStreams !== []) {
            $reserve = max(1, (int) floor($maxUnits * (float) ($config['expansion_share'] ?? 0.4)));
            $reservedAdmitted = 0;

            foreach ($targetStreams as $streamKey) {
                foreach ($streams[$streamKey] as $unit) {
                    if ($reservedAdmitted >= $reserve) {
                        break 2;
                    }

                    // Reserve keys on RELATION/STATE hits (the asked
                    // dimension), not on mere entity mentions — otherwise
                    // every "Atticus said…" sentence would qualify.
                    if (empty($unit->retrievalMeta['relation_hit'])) {
                        continue;
                    }

                    $chunkKey = $unit->retrievalMeta['chunk_public_id'] ?? spl_object_id($unit);

                    if (($perChunk[$chunkKey] ?? 0) >= $maxPerChunk) {
                        continue;
                    }

                    if ($admit($unit)) {
                        $perChunk[$chunkKey] = ($perChunk[$chunkKey] ?? 0) + 1;
                        $perRegion[$unit->bookAssetId.':'.$unit->spineIndex] = ($perRegion[$unit->bookAssetId.':'.$unit->spineIndex] ?? 0) + 1;
                        $unit->retrievalMeta['selection'] = 'expansion_reserve';
                        $reservedAdmitted++;
                    }
                }
            }
        }

        // ── Stage 1: breadth across chunks / source regions ──────────
        // Within a chunk, units that carry the subquestion's anchor terms
        // are admitted FIRST (they are the ones likely to hold the
        // asked fact), then the remaining sentences in order.
        if ($interleave && count($streams) > 1) {
            $sequence = $this->prioritizeAnchorUnits($sequence);
        }

        foreach ($sequence as $unit) {
            $chunkKey = $unit->retrievalMeta['chunk_public_id'] ?? spl_object_id($unit);
            $regionKey = $unit->bookAssetId.':'.$unit->spineIndex;

            if (count($selected) >= $breadthCap
                || ($perChunk[$chunkKey] ?? 0) >= $maxPerChunk
                || ($perRegion[$regionKey] ?? 0) >= $maxPerRegion) {
                $deferred[] = $unit;
                $heldForDepth++;

                continue;
            }

            if ($admit($unit)) {
                $perChunk[$chunkKey] = ($perChunk[$chunkKey] ?? 0) + 1;
                $perRegion[$regionKey] = ($perRegion[$regionKey] ?? 0) + 1;
                $unit->retrievalMeta['selection'] = 'breadth';
            }
        }

        // ── Stage 2: depth — held-back units ─────────────────────────
        // Interleaved (multi-stream) packets keep stream fairness in the
        // depth pass too (round-robin across the streams' deferred
        // units); single-stream packets take them in relevance order.
        if ($interleave && count($streams) > 1) {
            $byStream = [];

            foreach ($deferred as $unit) {
                $byStream[$unit->retrievalMeta['subquestion'] ?? $unit->bookAssetId][] = $unit;
            }

            $deferred = [];
            $exhausted = false;

            for ($round = 0; ! $exhausted; $round++) {
                $exhausted = true;

                foreach ($byStream as $list) {
                    if (isset($list[$round])) {
                        $deferred[] = $list[$round];
                        $exhausted = false;
                    }
                }
            }
        }

        foreach ($deferred as $unit) {
            if (count($selected) >= $maxUnits) {
                $droppedBudget++;

                continue;
            }

            if ($admit($unit)) {
                $unit->retrievalMeta['selection'] = 'depth';
            }
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

        $distinctChunks = [];
        $distinctRegions = [];
        $breadth = 0;

        foreach ($selected as $unit) {
            $distinctChunks[$unit->retrievalMeta['chunk_public_id'] ?? spl_object_id($unit)] = true;
            $distinctRegions[$unit->bookAssetId.':'.$unit->spineIndex] = true;

            if (($unit->retrievalMeta['selection'] ?? null) === 'breadth') {
                $breadth++;
            }
        }

        $stats = [
            'units' => count($selected),
            'chars' => $chars,
            'per_asset' => $perAsset,
            'dropped_duplicates' => $droppedDuplicates,
            'dropped_budget' => $droppedBudget,
            // Diversity (NOT recall): distinct retrieved chunks and
            // source regions represented, breadth vs depth admissions,
            // and units held back by the per-chunk/region caps in stage 1.
            'distinct_chunks' => count($distinctChunks),
            'distinct_regions' => count($distinctRegions),
            'breadth_units' => $breadth,
            'depth_units' => count($selected) - $breadth,
            'held_for_depth' => $heldForDepth,
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
