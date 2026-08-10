<?php

use App\Console\Commands\SchedulerHealthcheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat consumed by mnemosyne:scheduler:healthcheck (the scheduler
// container healthcheck): proves schedule:work is actually ticking.
Schedule::call(function () {
    Cache::put(SchedulerHealthcheck::HEARTBEAT_CACHE_KEY, now()->timestamp, 600);
})->name('scheduler-heartbeat')->everyMinute();

// Stale ingestion detection: marks a run whose worker went silent mid-stage
// failed (admin-retryable). A queued backlog wait is NEVER treated as stale
// (that caused duplicate dispatch); recovering a genuinely lost queued job
// is the explicit, guard-safe `mnemosyne:ingestion:requeue-lost` command.
// Threshold is configurable via MNEMOSYNE_INGESTION_STALE_MINUTES.
Schedule::command('mnemosyne:ingestion:detect-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();
