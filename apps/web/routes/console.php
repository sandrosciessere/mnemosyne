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

// Stale ingestion detection: marks dead runs failed (admin-retryable) and
// requeues queued runs whose job was lost. Threshold is configurable via
// MNEMOSYNE_INGESTION_STALE_MINUTES — never a tight arbitrary timeout.
Schedule::command('mnemosyne:ingestion:detect-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();
