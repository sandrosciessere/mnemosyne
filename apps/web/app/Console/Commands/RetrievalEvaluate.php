<?php

namespace App\Console\Commands;

use App\Models\BookAsset;
use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\EvaluationRunner;
use App\Services\Retrieval\Retrievers\ExactRetriever;
use Illuminate\Console\Command;

class RetrievalEvaluate extends Command
{
    protected $signature = 'mnemosyne:retrieval:evaluate
        {--cases= : Path to a cases JSON (default: tests/retrieval/evaluation-cases.json)}
        {--generation= : Generation public id (default: active)}
        {--book-map= : book_ref=asset_ulid pairs, comma separated (default: resolve by matching phrases)}
        {--top-k=10}
        {--modes=exact,lexical,dense,hybrid,hybrid+rerank}';

    protected $description = 'Run the synthetic retrieval benchmark against a generation (Recall@K / MRR per mode)';

    public function handle(EvaluationRunner $runner): int
    {
        $casesPath = (string) ($this->option('cases') ?: base_path('tests/retrieval/evaluation-cases.json'));

        if (! is_file($casesPath)) {
            $this->error("Cases file not found: {$casesPath}");

            return self::FAILURE;
        }

        $cases = json_decode((string) file_get_contents($casesPath), true);

        $generation = $this->option('generation')
            ? RetrievalGeneration::query()->where('public_id', (string) $this->option('generation'))->first()
            : RetrievalGeneration::active();

        if ($generation === null) {
            $this->error('No generation.');

            return self::FAILURE;
        }

        $bookMap = $this->resolveBookMap($cases, $generation);

        if ($bookMap === null) {
            return self::FAILURE;
        }

        $metrics = $runner->run(
            $generation,
            $cases,
            $bookMap,
            array_map('trim', explode(',', (string) $this->option('modes'))),
            max(1, (int) $this->option('top-k')),
        );

        $this->info("generation {$generation->public_id} — top_k={$metrics['top_k']}");
        $this->table(
            ['mode', 'Recall@K', 'MRR', 'found/cases'],
            collect($metrics['modes'])->map(fn ($m, $mode) => [
                $mode, sprintf('%.4f', $m['recall_at_k']), sprintf('%.4f', $m['mrr']), "{$m['found']}/{$m['cases']}",
            ])->all(),
        );

        foreach ($metrics['per_case'] as $mode => $caseResults) {
            foreach ($caseResults as $caseId => $result) {
                if (($result['false_positive'] ?? false) === true) {
                    $this->error("  FALSE POSITIVE [{$mode}] {$caseId}");
                } elseif (array_key_exists('rank', $result) && $result['rank'] === null) {
                    $this->warn("  miss [{$mode}] {$caseId}");
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, BookAsset>|null */
    private function resolveBookMap(array $cases, RetrievalGeneration $generation): ?array
    {
        $explicit = (string) $this->option('book-map');
        $map = [];

        if ($explicit !== '') {
            foreach (explode(',', $explicit) as $pair) {
                [$ref, $ulid] = array_pad(explode('=', $pair, 2), 2, null);
                $asset = BookAsset::query()->where('public_id', (string) $ulid)->first();

                if ($asset === null) {
                    $this->error("Unknown asset for book_ref {$ref}.");

                    return null;
                }

                $map[trim((string) $ref)] = $asset;
            }

            return $map;
        }

        // Resolve each book_ref by locating the expected phrase among the
        // generation's indexed chunks (works only for synthetic corpora).
        $refs = collect($cases['cases'])
            ->pluck('expected.book_ref')
            ->filter()
            ->unique();

        foreach ($refs as $ref) {
            $phrase = collect($cases['cases'])
                ->first(fn ($case) => ($case['expected']['book_ref'] ?? null) === $ref)['expected']['phrase'];

            $chunk = RetrievalChunk::query()
                ->where('retrieval_generation_id', $generation->id)
                ->where('source_text', 'like', '%'.ExactRetriever::escapeLike($phrase).'%')
                ->first();

            if ($chunk === null) {
                $this->error("Cannot resolve book_ref {$ref}: phrase not indexed (index the synthetic corpus first or pass --book-map).");

                return null;
            }

            $map[$ref] = $chunk->asset;
        }

        return $map;
    }
}
