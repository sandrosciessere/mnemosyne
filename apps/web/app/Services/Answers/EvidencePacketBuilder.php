<?php

namespace App\Services\Answers;

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

        $sequence = [];

        if ($interleaveUnits) {
            // Round-robin one unit per book per round (book order =
            // scope order): the packet head is book-fair by
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
        foreach ($selected as $unit) {
            $perAsset[$unit->bookPublicId] = ($perAsset[$unit->bookPublicId] ?? 0) + 1;
        }

        return new EvidencePacket(
            units: $selected,
            stats: [
                'units' => count($selected),
                'chars' => $chars,
                'per_asset' => $perAsset,
                'dropped_duplicates' => $droppedDuplicates,
                'dropped_budget' => $droppedBudget,
            ],
            diagnostics: $diagnostics,
        );
    }
}
