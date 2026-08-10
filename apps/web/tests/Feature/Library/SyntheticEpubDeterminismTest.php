<?php

namespace Tests\Feature\Library;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticEpub;
use Tests\TestCase;

/**
 * The exact-duplicate proof depends on identical logical inputs producing
 * byte-identical EPUBs (same SHA-256). ZipArchive otherwise embeds the
 * wall-clock time in every local file header, which made the E2E dedup
 * test flaky. These tests pin the determinism invariant directly, without
 * needing the database or the worker.
 */
class SyntheticEpubDeterminismTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function builders(): array
    {
        return [
            'epub3' => ['epub3'],
            'epub2' => ['epub2'],
            'nestedHeadings' => ['nestedHeadings'],
            'manySpineDocuments' => ['manySpineDocuments'],
            'richContributors' => ['richContributors'],
            'multilingual' => ['multilingual'],
            'footnotes' => ['footnotes'],
            'tablesAndCaptions' => ['tablesAndCaptions'],
            'svgAndImages' => ['svgAndImages'],
            'recoverableXhtml' => ['recoverableXhtml'],
        ];
    }

    #[DataProvider('builders')]
    public function test_builder_is_byte_deterministic(string $builder): void
    {
        $a = tempnam(sys_get_temp_dir(), 'det-a-').'.epub';
        $b = tempnam(sys_get_temp_dir(), 'det-b-').'.epub';

        try {
            SyntheticEpub::{$builder}($a);
            // Force a wall-clock gap: a naive ZipArchive stamps different
            // mtimes across this boundary and the hashes would diverge.
            usleep(1_100_000);
            SyntheticEpub::{$builder}($b);

            $this->assertSame(
                hash_file('sha256', $a),
                hash_file('sha256', $b),
                "Fixture builder {$builder} is not byte-deterministic; the exact-duplicate proof would be flaky.",
            );
        } finally {
            File::delete([$a, $b]);
        }
    }
}
