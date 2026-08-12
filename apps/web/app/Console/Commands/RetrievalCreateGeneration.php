<?php

namespace App\Console\Commands;

use App\Services\Retrieval\RetrievalGenerationManager;
use Illuminate\Console\Command;

class RetrievalCreateGeneration extends Command
{
    protected $signature = 'mnemosyne:retrieval:create-generation';

    protected $description = 'Snapshot the current retrieval component configuration into a new (building) generation';

    public function handle(RetrievalGenerationManager $manager): int
    {
        $generation = $manager->create();
        $embedding = $generation->config['embedding'];

        $this->info("generation {$generation->public_id} created (building)");
        $this->line("  chunker      {$generation->chunker_version} ({$generation->chunker_config_hash})");
        $this->line("  embedding    {$embedding['model_key']} ({$embedding['hf_id']}@".substr((string) $embedding['revision'], 0, 12).") {$embedding['dimensions']}d cosine");
        $this->line("  reranker     {$generation->config['reranker']['model_key']}");
        $this->line('  next:        mnemosyne:retrieval:index --all-ready --generation='.$generation->public_id);

        return self::SUCCESS;
    }
}
