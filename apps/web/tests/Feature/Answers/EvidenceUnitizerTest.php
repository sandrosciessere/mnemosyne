<?php

namespace Tests\Feature\Answers;

use App\Models\RetrievalChunk;
use App\Services\Answers\EvidenceUnitizer;
use App\Services\Retrieval\Chunker;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class EvidenceUnitizerTest extends TestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
    }

    public function test_windows_are_lossless_and_bounded(): void
    {
        $unitizer = new EvidenceUnitizer(80);

        $text = 'Prima frase breve. Seconda frase un poco più lunga della prima! Terza frase che conclude il paragrafo con calma? Quarta frase finale.';
        $windows = $unitizer->windows($text);

        $rebuilt = '';
        foreach ($windows as [$offset, $window]) {
            $this->assertSame($offset, mb_strlen($rebuilt), 'offsets must be exact codepoint positions');
            $this->assertLessThanOrEqual(80, mb_strlen($window));
            $rebuilt .= $window;
        }

        $this->assertSame($text, $rebuilt, 'windows must concatenate to the input');
        $this->assertGreaterThan(1, count($windows));
    }

    public function test_pathological_unbroken_text_is_hard_split_without_byte_cuts(): void
    {
        $unitizer = new EvidenceUnitizer(50);
        // No sentence boundaries, no spaces, multibyte chars: forces the
        // hard codepoint cut — which must never split inside a char.
        $text = str_repeat('caffè🎻', 30);
        $windows = $unitizer->windows($text);

        $rebuilt = '';
        foreach ($windows as [, $window]) {
            $this->assertLessThanOrEqual(50, mb_strlen($window));
            $this->assertTrue(mb_check_encoding($window, 'UTF-8'));
            $rebuilt .= $window;
        }
        $this->assertSame($text, $rebuilt);
    }

    public function test_units_are_exact_canonical_slices_in_all_coordinate_systems(): void
    {
        $built = $this->unicodeBook();
        $canonical = $built['canonical'];

        $chunks = RetrievalChunk::query()->with(['spans', 'asset'])->get();
        $this->assertNotEmpty($chunks);

        $unitizer = new EvidenceUnitizer((int) config('mnemosyne.answers.evidence.unit_max_chars'));
        $unitCount = 0;

        foreach ($chunks as $chunk) {
            foreach ($unitizer->unitsForChunk($chunk, ['branch' => 'test']) as $unit) {
                $unitCount++;

                $this->assertSame(
                    mb_substr($canonical, $unit->canonicalStart, $unit->canonicalEnd - $unit->canonicalStart),
                    $unit->text,
                    'codepoint invariant',
                );
                $this->assertSame(
                    $this->utf16Slice($canonical, $unit->utf16Start, $unit->utf16End),
                    $unit->text,
                    'utf16 invariant',
                );
                $this->assertSame(
                    Chunker::utf16Length($unit->text),
                    $unit->utf16End - $unit->utf16Start,
                );
                $this->assertNotSame('', trim($unit->text));
                $this->assertNotNull($unit->sourceHash);
                $this->assertSame($built['asset']->content_sha256, $unit->sourceContentSha256);
            }
        }

        $this->assertGreaterThan(0, $unitCount);
    }

    public function test_oversized_spans_split_at_sentence_boundaries_with_exact_provenance(): void
    {
        // One node far larger than the unit budget.
        $sentences = [];
        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "La frase numero {$i} del lunghissimo paragrafo continua a raccontare dettagli del faro con precisione.";
        }
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Capitolo unico', 'heading_path' => ['Capitolo unico']],
                ['text' => implode(' ', $sentences)],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $unitizer = new EvidenceUnitizer(300);
        $canonical = $built['canonical'];
        $seenSplitSpan = false;

        foreach (RetrievalChunk::query()->with(['spans', 'asset'])->get() as $chunk) {
            $units = $unitizer->unitsForChunk($chunk, []);
            $bySpan = [];

            foreach ($units as $unit) {
                $this->assertLessThanOrEqual(300, $unit->charCount());
                $this->assertSame(
                    mb_substr($canonical, $unit->canonicalStart, $unit->canonicalEnd - $unit->canonicalStart),
                    $unit->text,
                );
                $bySpan[$unit->retrievalMeta['span_ordinal']][] = $unit;
            }

            foreach ($bySpan as $spanUnits) {
                if (count($spanUnits) > 1) {
                    $seenSplitSpan = true;

                    // Windows of one span must be contiguous.
                    for ($i = 1; $i < count($spanUnits); $i++) {
                        $this->assertSame(
                            $spanUnits[$i - 1]->canonicalEnd,
                            $spanUnits[$i]->canonicalStart,
                        );
                    }
                }
            }
        }

        $this->assertTrue($seenSplitSpan, 'expected at least one span split into multiple units');
    }
}
