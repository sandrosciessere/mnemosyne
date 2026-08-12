<?php

use App\Exceptions\Library\InvalidTransitionException;
use App\Exceptions\Library\StorageException;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], __DIR__.'/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The app only ever receives traffic from the stack's own nginx,
        // which in turn is proxied by the host nginx (TLS termination).
        $middleware->trustProxies(at: '*');

        // First-party same-origin SPA: browser sessions authenticate
        // /api/v1 via Sanctum's stateful middleware (session + CSRF for
        // requests whose Origin/Referer is in sanctum.stateful, which
        // derives from APP_URL). Bearer-token auth is unaffected.
        $middleware->statefulApi();

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Domain transition violations are user errors, not server bugs:
        // stable JSON error shape on the API, flash message on the web.
        $exceptions->render(function (InvalidTransitionException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                        'details' => (object) [],
                    ],
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
        });

        $exceptions->render(function (StorageException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                        'details' => (object) [],
                    ],
                ], 507);
            }

            return back()->with('error', $exception->getMessage());
        });
    })->create();
