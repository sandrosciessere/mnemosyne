<?php

namespace App\Providers;

use App\Services\Answers\Providers\FakeGenerationProvider;
use App\Services\Answers\Providers\FakeVerifierProvider;
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
        // Deterministic provider doubles resolve to ONE instance per
        // test request so scripted outputs registered by the test are
        // seen by the pipeline. Their constructors refuse to run in
        // production.
        $this->app->singleton(FakeGenerationProvider::class);
        $this->app->singleton(FakeVerifierProvider::class);
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

        // Neighbor/context expansion is cheap but user-triggered per
        // click: bound it generously (a reader session paging around a
        // book stays far below), never below normal UI usage.
        RateLimiter::for('retrieval-neighbors', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->id !== null ? 'user:'.$request->user()->id : 'ip:'.$request->ip(),
            );
        });

        // Grounded answer submission: each answer costs minutes of local
        // model CPU — the per-minute limit complements the per-user
        // active-run cap enforced in the submission service.
        RateLimiter::for('answers', function (Request $request) {
            $perMinute = (int) config('mnemosyne.answers.submissions_per_minute', 6);

            return Limit::perMinute($perMinute)->by(
                $request->user()?->id !== null ? 'user:'.$request->user()->id : 'ip:'.$request->ip(),
            );
        });
    }
}
