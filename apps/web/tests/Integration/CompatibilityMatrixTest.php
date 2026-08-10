<?php

namespace Tests\Integration;

use App\Enums\IngestionRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * First-milestone acceptance gate: ten genuinely heterogeneous synthetic
 * EPUBs must cross the REAL pipeline (PostgreSQL + Python worker), plus
 * the hostile/negative set. The full matrix is documented in
 * docs/architecture/epub-ingestion.md.
 */
class CompatibilityMatrixTest extends IntegrationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRealWorker();
    }

    // ---------------------------------------------------------------
    // Positive matrix (10 heterogeneous fixtures)
    // ---------------------------------------------------------------

    public function test_matrix_01_epub2_with_ncx(): void
    {
        $asset = $this->submitFixture('epub2')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertStringStartsWith('2', $asset->epub_version);
        $this->assertSame('it', $asset->edition->language);
        $this->assertGreaterThanOrEqual(2, $asset->structure_summary['toc_entries']);
    }

    public function test_matrix_02_epub3_with_nav(): void
    {
        $asset = $this->submitFixture('epub3')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertSame('3.0', $asset->epub_version);
        $this->assertSame('The Synthetic Chronicle', $asset->edition->title);
        $this->assertSame('9780316769488', $asset->edition->identifiers->firstWhere('scheme', 'isbn13')->value);
        $structure = json_decode(file_get_contents($this->artifactDir($asset).'/structure.json'), true);
        $this->assertNotEmpty($structure['toc']);
    }

    public function test_matrix_03_nested_heading_hierarchy(): void
    {
        $asset = $this->submitFixture('nestedHeadings')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertGreaterThanOrEqual(6, $asset->structure_summary['sections']);

        // The h4 subsection paragraph carries the full 4-level path.
        $nodes = $this->readAllNodes($asset);
        $deepest = collect($nodes)->first(
            fn ($node) => str_contains($node['text'], 'Subsection text at depth four'),
        );
        $this->assertNotNull($deepest);
        $this->assertSame(
            ['Part One', 'Chapter One', 'Section 1.1', 'Subsection 1.1.1'],
            $deepest['heading_path'],
        );
    }

    public function test_matrix_04_many_spine_documents_in_order(): void
    {
        $asset = $this->submitFixture('manySpineDocuments')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertSame(8, $asset->structure_summary['spine_items']);
        $this->assertCount(8, glob($this->artifactDir($asset).'/spine/*.jsonl'));

        // Reading order follows the spine, not zip entry order.
        $headings = collect($this->readAllNodes($asset))
            ->filter(fn ($node) => $node['type'] === 'heading')
            ->pluck('text')->values()->all();
        $this->assertSame(array_map(fn ($n) => "Part {$n}", range(1, 8)), $headings);
    }

    public function test_matrix_05_multiple_contributors_and_roles(): void
    {
        $asset = $this->submitFixture('richContributors')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $roles = $asset->edition->contributors
            ->mapWithKeys(fn ($contributor) => [$contributor->pivot->credited_as => $contributor->pivot->role]);
        $this->assertSame('aut', $roles['Prima Author']);
        $this->assertSame('aut', $roles['Segunda Author']);
        $this->assertSame('edt', $roles['Eddie Editor']);
        $this->assertSame('ill', $roles['Iva Illustrator']);
    }

    public function test_matrix_06_multilingual_metadata_and_content(): void
    {
        $asset = $this->submitFixture('multilingual')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $this->assertContains('el', $asset->extracted_metadata['languages']);

        $nodes = collect($this->readAllNodes($asset));
        $greek = $nodes->first(fn ($node) => ($node['lang'] ?? null) === 'el');
        $this->assertNotNull($greek);
        $this->assertStringContainsString('μητέρα', $greek['text']);
        $this->assertNotNull($nodes->first(fn ($node) => ($node['lang'] ?? null) === 'ja'));

        // Non-Latin offsets: codepoint slicing must reproduce the text.
        $canonical = file_get_contents($this->artifactDir($asset).'/canonical.txt');
        $this->assertSame(
            $greek['text'],
            mb_substr($canonical, $greek['normalized_start'], $greek['normalized_end'] - $greek['normalized_start']),
        );
    }

    public function test_matrix_07_footnotes_and_internal_references(): void
    {
        $asset = $this->submitFixture('footnotes')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $nodes = collect($this->readAllNodes($asset));

        // The annotated paragraph carries its noteref targets.
        $annotated = $nodes->first(fn ($node) => str_contains($node['text'], 'catalogued'));
        $this->assertNotNull($annotated);
        $refs = collect($annotated['refs'] ?? []);
        $this->assertTrue($refs->contains(fn ($ref) => $ref['kind'] === 'noteref' && $ref['fragment'] === 'n1'));
        $this->assertTrue($refs->contains(fn ($ref) => $ref['fragment'] === 'localnote'));

        // Footnote bodies are extracted and flagged.
        $note = $nodes->first(fn ($node) => str_contains($node['text'], 'First catalogue note'));
        $this->assertNotNull($note);
        $this->assertTrue($note['is_note'] ?? false);

        // Cross-document plain hyperlink preserved.
        $seeAlso = $nodes->first(fn ($node) => str_contains($node['text'], 'appendix note'));
        $this->assertTrue(
            collect($seeAlso['refs'] ?? [])->contains(fn ($ref) => $ref['fragment'] === 'extra'),
        );
    }

    public function test_matrix_08_tables_and_captions(): void
    {
        $asset = $this->submitFixture('tablesAndCaptions')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $nodes = collect($this->readAllNodes($asset));

        $table = $nodes->first(fn ($node) => $node['type'] === 'table');
        $this->assertNotNull($table);
        $this->assertSame('Inventory of the reading room', $table['table']['caption']);
        $this->assertContains(['Item', 'Count'], $table['table']['rows']);
        $this->assertContains(['Chairs', '12'], $table['table']['rows']);

        $caption = $nodes->first(fn ($node) => str_contains($node['text'], 'reading room plan'));
        $this->assertNotNull($caption, 'figcaption text must survive extraction');
    }

    public function test_matrix_09_svg_and_images_with_surrounding_prose(): void
    {
        $asset = $this->submitFixture('svgAndImages')->latestRun->asset;

        $this->assertSame('ready_for_enrichment', $asset->ingestion_status->value);
        $nodes = collect($this->readAllNodes($asset));

        $this->assertNotNull($nodes->first(fn ($node) => str_contains($node['text'], 'Before the diagram')));
        $this->assertNotNull($nodes->first(fn ($node) => str_contains($node['text'], 'the prose continues')));

        $figures = $nodes->filter(fn ($node) => ! empty($node['has_image']));
        $this->assertGreaterThanOrEqual(2, $figures->count());
        $alts = $figures->map(fn ($node) => $node['image']['alt'] ?? null)->filter()->values();
        $this->assertContains('A circle diagram', $alts);
        $this->assertContains('An archival photograph', $alts);
    }

    public function test_matrix_10_recoverable_html_style_markup(): void
    {
        $submission = $this->submitFixture('recoverableXhtml');
        $run = $submission->latestRun;
        $asset = $run->asset;

        // Success — but visibly WITH warnings (fallback parser was used).
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame('ready_for_enrichment_with_warnings', $asset->ingestion_status->value);
        $this->assertTrue(
            $run->events()->where('type', 'stage.warning')->get()
                ->contains(fn ($event) => ($event->payload['code'] ?? '') === 'XHTML_NOT_WELL_FORMED'),
        );

        $nodes = collect($this->readAllNodes($asset));
        $this->assertNotNull($nodes->first(fn ($node) => str_contains($node['text'], 'second line of the same paragraph')));
    }

    // ---------------------------------------------------------------
    // Source fidelity round-trip (requirement D)
    // ---------------------------------------------------------------

    public function test_every_node_offset_reproduces_its_text_from_canonical(): void
    {
        $asset = $this->submitFixture('epub3')->latestRun->asset;
        $canonical = file_get_contents($this->artifactDir($asset).'/canonical.txt');

        // canonical.txt IS the fingerprint corpus.
        $this->assertSame($asset->content_sha256, hash('sha256', $canonical));

        $checked = 0;
        foreach ($this->readAllNodes($asset) as $node) {
            if ($node['normalized_start'] === null) {
                continue;
            }

            $slice = mb_substr(
                $canonical,
                $node['normalized_start'],
                $node['normalized_end'] - $node['normalized_start'],
            );
            $this->assertSame($node['text'], $slice, "offset mismatch for node {$node['node_id']}");
            $this->assertNotEmpty($node['source_hash']);
            $this->assertNotEmpty($node['source']['href']);
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_sanitized_xhtml_preserves_anchors_and_strips_unsafe_content(): void
    {
        $asset = $this->submitFixture('remoteAndScript')->latestRun->asset;

        // Remote references are a warning, not a failure.
        $this->assertSame('ready_for_enrichment_with_warnings', $asset->ingestion_status->value);

        $sanitizedFiles = glob($this->artifactDir($asset).'/sanitized/*.xhtml');
        $this->assertNotEmpty($sanitizedFiles);
        $sanitized = file_get_contents($sanitizedFiles[0]);

        // Unsafe material is gone…
        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('https://evil.example', $sanitized);
        // …anchors/ids and traceability survive.
        $this->assertStringContainsString('id="h"', $sanitized);
        $this->assertStringContainsString('data-mnemosyne-source-href', $sanitized);
        $this->assertStringContainsString('Readable prose that must survive sanitization.', $sanitized);
    }

    // ---------------------------------------------------------------
    // Negative / security matrix
    // ---------------------------------------------------------------

    public function test_negative_malformed_container_fails(): void
    {
        $run = $this->submitFixture('malformed')->latestRun;

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertNull($run->asset->storage_path);
    }

    public function test_negative_invalid_opf_xml_fails(): void
    {
        $run = $this->submitFixture('invalidOpfXml')->latestRun;

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('EPUB_OPF_UNREADABLE', $run->last_error_code);
    }

    public function test_negative_path_traversal_is_hard_blocked(): void
    {
        $run = $this->submitFixture('pathTraversal')->latestRun;

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame('ZIP_PATH_TRAVERSAL', $run->last_error_code);
        $this->assertFileDoesNotExist($this->testDataRoot.'/evil.txt');
    }

    public function test_negative_encrypted_content_needs_review_not_overrideable(): void
    {
        $run = $this->submitFixture('encryptedContent')->latestRun;

        $this->assertSame(IngestionRunStatus::NeedsReview, $run->status);
        $issue = collect($run->review_issues)->firstWhere('code', 'DRM_ENCRYPTED_CONTENT');
        $this->assertNotNull($issue);
        $this->assertFalse($issue['overrideable']);
    }

    public function test_negative_missing_spine_resource_is_reviewable_and_overrideable(): void
    {
        $submission = $this->submitFixture('missingResource');
        $run = $submission->latestRun;

        $this->assertSame(IngestionRunStatus::NeedsReview, $run->status);
        $issue = collect($run->review_issues)->firstWhere('code', 'SPINE_RESOURCE_MISSING');
        $this->assertNotNull($issue);
        $this->assertTrue($issue['overrideable']);

        // The admin may accept the loss; the run completes with warnings.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post('/admin/processing/runs/'.$run->public_id.'/override', ['code' => 'SPINE_RESOURCE_MISSING'])
            ->assertRedirect();
        $this->drainIngestionQueue();

        $run->refresh();
        $this->assertSame(IngestionRunStatus::Succeeded, $run->status);
        $this->assertSame('ready_for_enrichment_with_warnings', $run->asset->ingestion_status->value);
    }
}
