<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SchedulerHealthcheck extends Command
{
    public const HEARTBEAT_CACHE_KEY = 'mnemosyne:scheduler:heartbeat';

    protected $signature = 'mnemosyne:scheduler:healthcheck';

    protected $description = 'Exit 0 when the scheduler heartbeat is fresh (container healthcheck)';

    public function handle(): int
    {
        $lastBeat = Cache::get(self::HEARTBEAT_CACHE_KEY);

        if ($lastBeat === null || now()->timestamp - (int) $lastBeat > 180) {
            $this->error('scheduler heartbeat is stale or missing');

            return self::FAILURE;
        }

        $this->info('scheduler heartbeat ok');

        return self::SUCCESS;
    }
}
