<?php

namespace App\Console\Commands;

use App\Models\RetrievalGeneration;
use App\Services\Retrieval\RetrievalGenerationManager;
use Illuminate\Console\Command;

class RetrievalActivateGeneration extends Command
{
    protected $signature = 'mnemosyne:retrieval:activate
        {generation : Public id of a built generation}
        {--allow-empty : Activate even with zero ready assets (bootstrap)}';

    protected $description = 'Atomically activate a retrieval generation (previous active becomes superseded, its data is preserved)';

    public function handle(RetrievalGenerationManager $manager): int
    {
        $generation = RetrievalGeneration::query()
            ->where('public_id', $this->argument('generation'))
            ->first();

        if ($generation === null) {
            $this->error('Unknown generation.');

            return self::FAILURE;
        }

        $manager->activate($generation, allowEmpty: (bool) $this->option('allow-empty'));

        $this->info("generation {$generation->public_id} is now ACTIVE");

        return self::SUCCESS;
    }
}
