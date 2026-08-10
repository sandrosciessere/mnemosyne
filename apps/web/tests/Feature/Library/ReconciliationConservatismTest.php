<?php

namespace Tests\Feature\Library;

use App\Models\BookAsset;
use App\Models\Contributor;
use App\Models\DuplicateCandidate;
use App\Models\Edition;
use App\Models\IngestionRun;
use App\Models\Work;
use App\Services\Ingestion\ReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Conservatism guarantees for bibliographic reconciliation: a normalized
 * name/title is EVIDENCE, never global identity; editions are only
 * auto-linked on strong non-contradicted evidence; missing metadata is
 * ABSENT (never agreement); conflicts open review candidates, never merges.
 */
class ReconciliationConservatismTest extends TestCase
{
    use RefreshDatabase;

    private function reconcileAsset(array $meta, array $overrides = []): BookAsset
    {
        $asset = BookAsset::factory()->create(array_merge([
            'extracted_metadata' => $meta,
        ], $overrides));

        $run = IngestionRun::factory()->create(['book_asset_id' => $asset->id]);

        app(ReconciliationService::class)->reconcile($run);

        return $asset->refresh();
    }

    // A / B — normalized name is evidence, never identity: no homonym collapse.
    public function test_two_books_crediting_same_name_yield_two_contributor_rows(): void
    {
        $this->reconcileAsset([
            'title' => 'Alpha Chronicles',
            'creators' => [['name' => 'John Smith', 'roles' => ['aut']]],
            'languages' => ['en'],
            'identifiers' => [],
        ]);

        $this->reconcileAsset([
            'title' => 'Beta Chronicles',
            'creators' => [['name' => 'John Smith', 'roles' => ['aut']]],
            'languages' => ['en'],
            'identifiers' => [],
        ]);

        $this->assertSame(
            2,
            Contributor::query()->where('normalized_name', 'john smith')->count(),
            'Two unrelated credits for the same name must remain distinct provisional rows.',
        );
    }

    // G — a filename-derived title must never establish identity.
    public function test_titleless_epubs_named_alike_do_not_match(): void
    {
        $meta = [
            'title' => '',
            'creators' => [['name' => 'Jane Doe', 'roles' => ['aut']]],
            'languages' => ['en'],
            'identifiers' => [],
        ];

        $first = $this->reconcileAsset($meta, ['original_filename' => 'book.epub']);
        $second = $this->reconcileAsset($meta, ['original_filename' => 'book.epub']);

        $this->assertNotSame($first->edition_id, $second->edition_id);
        $this->assertSame(2, Work::query()->count(), 'Filename titles must not reuse a Work.');
        $this->assertSame('unresolved', $second->reconciliation['confidence']);
        $this->assertSame('filename', $second->reconciliation['evidence']['title_source']);
        // Display title survives as a fallback, but never as matching evidence.
        $this->assertSame('book', $first->edition->title);
    }

    // D (Path A) + H — canonical ISBN-10 ↔ ISBN-13 equivalence auto-links.
    public function test_isbn10_edition_is_matched_by_equivalent_isbn13(): void
    {
        $work = Work::factory()->create(['normalized_title' => 'the great book']);
        $edition = Edition::factory()->create([
            'work_id' => $work->id,
            'title' => 'The Great Book',
            'language' => 'en',
        ]);
        $contributor = Contributor::factory()->create([
            'name' => 'Ada Author',
            'normalized_name' => Contributor::normalizeName('Ada Author'),
        ]);
        $edition->contributors()->attach($contributor, ['role' => 'aut', 'credited_as' => 'Ada Author', 'position' => 0]);
        // Existing edition carries the ISBN-10; its canonical form is the ISBN-13.
        $edition->identifiers()->create([
            'scheme' => 'isbn10',
            'value' => '0316769487',
            'canonical_scheme' => 'isbn13',
            'canonical_value' => '9780316769488',
            'raw_value' => 'urn:isbn:0316769487',
        ]);

        $asset = $this->reconcileAsset([
            'title' => 'The Great Book',
            'creators' => [['name' => 'Ada Author', 'roles' => ['aut']]],
            'languages' => ['en'],
            'identifiers' => [[
                'scheme' => 'isbn13',
                'value' => '9780316769488',
                'isbn13' => '9780316769488',
                'raw' => 'urn:isbn:9780316769488',
            ]],
        ]);

        $this->assertSame($edition->id, $asset->edition_id, 'ISBN-13 must match the equivalent ISBN-10 edition.');
        $this->assertSame('identifier_and_title', $asset->reconciliation['method']);
        $this->assertSame('high_confidence', $asset->reconciliation['confidence']);
        $this->assertSame('agree', $asset->reconciliation['evidence']['dimensions']['identifier']);
        // Item H: the incoming asset's identifier is attached on adoption.
        $this->assertTrue($edition->identifiers()->where('value', '9780316769488')->exists());
    }

    // C / J — same title+creator+language but conflicting ISBN: distinct
    // editions under one work + a review candidate, never a merge.
    public function test_conflicting_isbn_yields_distinct_editions_and_candidate(): void
    {
        $shared = [
            'title' => 'Shared Title',
            'creators' => [['name' => 'Sam Writer', 'roles' => ['aut']]],
            'languages' => ['en'],
        ];

        $first = $this->reconcileAsset($shared + ['identifiers' => [[
            'scheme' => 'isbn13', 'value' => '9780000000001', 'isbn13' => '9780000000001',
        ]]]);
        $second = $this->reconcileAsset($shared + ['identifiers' => [[
            'scheme' => 'isbn13', 'value' => '9780000000002', 'isbn13' => '9780000000002',
        ]]]);

        $this->assertNotSame($first->edition_id, $second->edition_id, 'Conflicting ISBN must never collapse editions.');
        $this->assertSame(1, Work::query()->count(), 'Same title+creator is same WORK, distinct editions.');

        $candidate = DuplicateCandidate::query()->where('reason', 'bibliographic_conflict')->sole();
        $this->assertSame('conflict', $candidate->evidence['dimensions']['identifier']);
        $this->assertSame('agree', $candidate->evidence['dimensions']['language']);
    }

    // E + C/D (Path B) — missing language is ABSENT: fingerprint match with
    // absent language does NOT auto-adopt the edition.
    public function test_missing_language_is_not_treated_as_agreement(): void
    {
        $sha = hash('sha256', 'identical-normalized-text');
        $meta = [
            'title' => 'Fingerprint Twin',
            'creators' => [['name' => 'Pat Penn', 'roles' => ['aut']]],
            'languages' => [],
            'identifiers' => [],
        ];

        $first = $this->reconcileAsset($meta, ['content_sha256' => $sha, 'content_fingerprint_version' => '1']);
        $second = $this->reconcileAsset($meta, ['content_sha256' => $sha, 'content_fingerprint_version' => '1']);

        $this->assertNotSame($first->edition_id, $second->edition_id, 'Absent language must block Path B adoption.');
        $this->assertSame(1, DuplicateCandidate::query()->where('reason', 'content_sha256_match')->count());
    }

    // C/D (Path B) — fingerprint + full agreement DOES adopt (control case).
    public function test_fingerprint_with_full_agreement_adopts_edition(): void
    {
        $sha = hash('sha256', 'agreeing-normalized-text');
        $meta = [
            'title' => 'Agreeing Twin',
            'creators' => [['name' => 'Lee Reed', 'roles' => ['aut']]],
            'languages' => ['en'],
            'identifiers' => [],
        ];

        $first = $this->reconcileAsset($meta, ['content_sha256' => $sha, 'content_fingerprint_version' => '1']);
        $second = $this->reconcileAsset($meta, ['content_sha256' => $sha, 'content_fingerprint_version' => '1']);

        $this->assertSame($first->edition_id, $second->edition_id);
        $this->assertSame('high_confidence', $second->reconciliation['confidence']);
        $this->assertSame('content_fingerprint_with_bibliographic_agreement', $second->reconciliation['method']);
        $this->assertSame(1, Work::query()->count());
    }

    // I — the duplicate-candidate pair is symmetric and DB-enforced; a
    // reversed/concurrent creation is ignored, not a crash.
    public function test_duplicate_candidate_pair_is_symmetric_and_db_enforced(): void
    {
        $assetA = BookAsset::factory()->create();
        $assetB = BookAsset::factory()->create();
        [$low, $high] = DuplicateCandidate::orderedPair($assetA->id, $assetB->id);

        $row = fn () => [
            'public_id' => strtolower((string) Str::ulid()),
            'book_asset_id' => $low,
            'duplicate_of_asset_id' => $high,
            'asset_low_id' => $low,
            'asset_high_id' => $high,
            'reason' => 'content_sha256_match',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('duplicate_candidates')->insert($row());

        // Reversed/concurrent creation on the same canonical pair is ignored.
        $ignored = DB::table('duplicate_candidates')->insertOrIgnore($row());
        $this->assertSame(0, $ignored);
        $this->assertSame(1, DuplicateCandidate::query()->forPair($assetB->id, $assetA->id)->count());

        // The symmetric unique is enforced by the database itself.
        $this->expectException(QueryException::class);
        DB::table('duplicate_candidates')->insert($row());
    }

    // K — reconciliation is idempotent and does not crash on re-entry or on
    // empty/null metadata.
    public function test_reentry_and_empty_metadata_are_safe(): void
    {
        $sha = hash('sha256', 'reentry-text');
        $meta = ['title' => '', 'creators' => [], 'languages' => [], 'identifiers' => []];

        $asset = BookAsset::factory()->create([
            'extracted_metadata' => $meta,
            'content_sha256' => $sha,
            'content_fingerprint_version' => '1',
        ]);
        $run = IngestionRun::factory()->create(['book_asset_id' => $asset->id]);

        $service = app(ReconciliationService::class);
        $service->reconcile($run);
        $editionId = $asset->refresh()->edition_id;

        // Re-entry keeps the same links and creates nothing new.
        $service->reconcile($run);
        $this->assertSame($editionId, $asset->refresh()->edition_id);
        $this->assertSame(1, Edition::query()->count());
    }
}
