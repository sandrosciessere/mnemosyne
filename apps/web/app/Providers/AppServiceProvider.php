<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-user submission throttle: a normal user must not be able to
        // fire hundreds of uploads; bulk import uses the CLI path instead.
        RateLimiter::for('submissions', function (Request $request) {
            $perHour = (int) config('mnemosyne.ingestion.rate_limits.submissions_per_hour', 30);

            return Limit::perHour($perHour)->by(
                $request->user()?->id !== null ? 'user:'.$request->user()->id : 'ip:'.$request->ip(),
            );
        });

        // Retrieval search: CPU-bound (embedding + reranking) — bound it.
        RateLimiter::for('retrieval', function (Request $request) {
            return Limit::perMinute(30)->by(
                $request->user()?->id !== null ? 'user:'.$request->user()->id : 'ip:'.$request->ip(),
            );
        });
    }
}
