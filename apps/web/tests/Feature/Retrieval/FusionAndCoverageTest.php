<?php

namespace Tests\Feature\Retrieval;

use App\Models\BookAsset;
use App\Models\RetrievalChunk;
use App\Services\Retrieval\CoverageSelector;
use App\Services\Retrieval\RankFusion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FusionAndCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function chunk(BookAsset $asset, int $ordinal, int $start, int $end): RetrievalChunk
    {
        $chunk = new RetrievalChunk;
        $chunk->forceFill([
            'retrieval_generation_id' => 1,
            'book_asset_id' => $asset->id,
            'ordinal' => $ordinal,
            'spine_index' => 0,
            'source_text' => str_repeat('x', max(1, $end - $start)),
            'char_count' => $end - $start,
            'token_estimate' => 1,
            'content_sha256' => hash('sha256', $asset->id.':'.$ordinal),
            'canonical_start' => $start,
            'canonical_end' => $end,
            'source_content_sha256' => str_repeat('0', 64),
        ]);
        $chunk->setRelation('asset', $asset);
        $chunk->id = $asset->id * 1000 + $ordinal;

        return $chunk;
    }

    public function test_weighted_rrf_combines_component_ranks(): void
    {
        $asset = BookAsset::factory()->create();
        $a = $this->chunk($asset, 1, 0, 100);
        $b = $this->chunk($asset, 2, 100, 200);
        $c = $this->chunk($asset, 3, 200, 300);

        $config = ['k' => 60, 'weights' => ['exact' => 2.0, 'lexical' => 1.0, 'dense' => 1.0]];

        $fused = (new RankFusion)->fuse([
            'exact' => [['chunk' => $a, 'rank' => 1]],
            'lexical' => [['chunk' => $b, 'rank' => 1], ['chunk' => $c, 'rank' => 2]],
            'dense' => [['chunk' => $c, 'rank' => 1], ['chunk' => $b, 'rank' => 2]],
        ], $config);

        // exact hit weight 2.0 dominates: a first.
        $this->assertSame($a->id, $fused[0]['chunk']->id);
        // b and c each have (1st + 2nd): identical scores → deterministic
        // tie-break by (asset, ordinal): b (ordinal 2) before c.
        $this->assertSame($b->id, $fused[1]['chunk']->id);
        $this->assertSame($c->id, $fused[2]['chunk']->id);
        $this->assertEqualsWithDelta(2.0 / 61, $fused[0]['rrf_score'], 1e-9);
        $this->assertArrayHasKey('exact', $fused[0]['components']);
        $this->assertArrayHasKey('lexical', $fused[1]['components']);
        $this->assertArrayHasKey('dense', $fused[1]['components']);
    }

    public function test_fusion_is_deterministic_for_repeated_input(): void
    {
        $asset = BookAsset::factory()->create();
        $chunks = array_map(fn ($i) => $this->chunk($asset, $i, $i * 100, $i * 100 + 100), range(1, 6));
        $config = ['k' => 60, 'weights' => ['lexical' => 1.0, 'dense' => 1.0]];

        $input = [
            'lexical' => array_map(fn ($i) => ['chunk' => $chunks[$i], 'rank' => $i + 1], [0, 2, 4]),
            'dense' => array_map(fn ($i) => ['chunk' => $chunks[$i], 'rank' => $i], [1, 3, 5]),
        ];

        $first = array_map(fn ($r) => $r['chunk']->id, (new RankFusion)->fuse($input, $config));
        $second = array_map(fn ($r) => $r['chunk']->id, (new RankFusion)->fuse($input, $config));

        $this->assertSame($first, $second);
    }

    public function test_coverage_selector_drops_overlapping_evidence_keeps_diverse(): void
    {
        $asset = BookAsset::factory()->create();

        // b overlaps a by 80% (overlap region 80 of b's 100 chars);
        // c is elsewhere in the book; d overlaps nothing.
        $a = $this->chunk($asset, 1, 0, 200);
        $b = $this->chunk($asset, 2, 120, 220);
        $c = $this->chunk($asset, 3, 1000, 1200);
        $otherAsset = BookAsset::factory()->create();
        $d = $this->chunk($otherAsset, 1, 0, 200);

        $ranked = [
            ['chunk' => $a, 'rrf_score' => 0.9],
            ['chunk' => $b, 'rrf_score' => 0.8],
            ['chunk' => $c, 'rrf_score' => 0.7],
            ['chunk' => $d, 'rrf_score' => 0.6],
        ];

        $outcome = (new CoverageSelector)->select($ranked, 10, 0.6);

        $ids = array_map(fn ($r) => $r['chunk']->id, $outcome['selected']);
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids, 'substantially overlapping evidence must be deduplicated');
        $this->assertContains($c->id, $ids);
        $this->assertContains($d->id, $ids, 'same offsets in a DIFFERENT book are not duplicates');
        $this->assertSame(1, $outcome['dropped_duplicates']);
    }

    public function test_coverage_selector_respects_top_k(): void
    {
        $asset = BookAsset::factory()->create();
        $ranked = array_map(
            fn ($i) => ['chunk' => $this->chunk($asset, $i, $i * 1000, $i * 1000 + 100), 'rrf_score' => 1 / $i],
            range(1, 8),
        );

        $outcome = (new CoverageSelector)->select($ranked, 3, 0.6);

        $this->assertCount(3, $outcome['selected']);
    }
}
