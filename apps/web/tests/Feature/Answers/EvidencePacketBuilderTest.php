<?php

namespace Tests\Feature\Answers;

use App\Enums\QueryIntent;
use App\Services\Answers\EvidencePacketBuilder;
use App\Services\Answers\RetrievalPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class EvidencePacketBuilderTest extends TestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
    }

    public function test_packet_units_are_deduplicated_despite_chunk_overlap(): void
    {
        $built = $this->lighthouseBook();
        $policy = (new RetrievalPolicyResolver)->resolve(QueryIntent::LocalExplanation, 1);

        // sqlite feature tests exercise the exact component (lexical and
        // dense are PostgreSQL-only and covered by integration tests),
        // so the question carries a source literal.
        $packet = app(EvidencePacketBuilder::class)->build(
            $built['generation'], [$built['asset']->id], 'pescatori del borgo', $policy,
        );

        $this->assertGreaterThan(0, $packet->unitCount());

        // No two units may share the same canonical identity.
        $identities = array_map(fn ($unit) => $unit->identity(), array_values($packet->units));
        $this->assertSame(count($identities), count(array_unique($identities)));

        // Keys are sequential opaque E-keys.
        $this->assertSame(
            array_map(fn ($i) => 'E'.($i + 1), array_keys(array_values($packet->units))),
            $packet->keys(),
        );

        // Budget respected.
        $this->assertLessThanOrEqual((int) config('mnemosyne.answers.evidence.max_units'), $packet->unitCount());
        $this->assertLessThanOrEqual((int) config('mnemosyne.answers.evidence.max_chars'), $packet->totalChars());
    }

    public function test_naive_global_topk_is_dominated_but_comparative_policy_covers_both_books(): void
    {
        $corpus = $this->comparativeCorpus();
        $generation = $corpus['generation'];
        $assetIds = [$corpus['a']['asset']->id, $corpus['b']['asset']->id];
        // Literal shared by both books (exact component works on sqlite);
        // a tight unit budget makes global rank order decide who fits.
        $question = 'faro';
        $builder = app(EvidencePacketBuilder::class);
        config(['mnemosyne.answers.evidence.max_units' => 4]);

        // Baseline: a NAIVE single global pass (point-lookup policy,
        // small Top-K) lets the faro-saturated book A monopolize the
        // packet head — this is the failure mode the owner demonstrated.
        $naive = $builder->build(
            $generation, $assetIds, $question,
            (new RetrievalPolicyResolver)->resolve(QueryIntent::PointLookup, 2),
        );
        $naivePerAsset = $naive->unitsPerAsset();
        $this->assertGreaterThan(
            $naivePerAsset[$corpus['b']['asset']->id] ?? 0,
            $naivePerAsset[$corpus['a']['asset']->id] ?? 0,
            'book A must dominate the naive packet for this regression to be meaningful',
        );

        // Comparative policy: bounded per-book retrieval before global
        // assembly gives book B its evidence opportunity.
        $comparative = $builder->build(
            $generation, $assetIds, $question,
            (new RetrievalPolicyResolver)->resolve(QueryIntent::ComparativeMultiBook, 2),
        );
        $perAsset = $comparative->unitsPerAsset();

        $this->assertGreaterThan(0, $perAsset[$corpus['a']['asset']->id] ?? 0, 'book A must contribute');
        $this->assertGreaterThan(0, $perAsset[$corpus['b']['asset']->id] ?? 0, 'book B must contribute');

        // Interleaving must give B space near the head, not only the tail.
        $firstFourAssets = array_map(
            fn ($unit) => $unit->bookAssetId,
            array_slice(array_values($comparative->units), 0, 4),
        );
        $this->assertContains($corpus['b']['asset']->id, $firstFourAssets);
    }

    public function test_quote_location_runs_exact_first_and_finds_the_literal(): void
    {
        $built = $this->lighthouseBook();
        $policy = (new RetrievalPolicyResolver)->resolve(QueryIntent::QuoteLocation, 1);

        $packet = app(EvidencePacketBuilder::class)->build(
            $built['generation'], [$built['asset']->id],
            'Dove appare la frase "la lanterna era accesa" nel libro?', $policy,
        );

        $this->assertNotEmpty($packet->units);
        $modes = array_column($packet->diagnostics['searches'], 'mode');
        $this->assertContains('exact', $modes);

        // The literal-bearing unit must be present in the packet.
        $texts = array_map(fn ($unit) => $unit->text, array_values($packet->units));
        $this->assertTrue(
            (bool) array_filter($texts, fn ($text) => str_contains($text, 'la lanterna era accesa')),
            'the exact-matching source unit must be in the packet',
        );
    }

    public function test_budget_truncation_is_counted_never_silent(): void
    {
        config(['mnemosyne.answers.evidence.max_units' => 2]);

        $built = $this->lighthouseBook();
        $policy = (new RetrievalPolicyResolver)->resolve(QueryIntent::LocalExplanation, 1);

        $packet = app(EvidencePacketBuilder::class)->build(
            $built['generation'], [$built['asset']->id], 'faro', $policy,
        );

        $this->assertSame(2, $packet->unitCount());
        $this->assertGreaterThan(0, $packet->stats['dropped_budget']);
    }
}
