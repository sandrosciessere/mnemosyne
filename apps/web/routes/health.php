<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Liveness/readiness endpoints for container orchestration and the host
 * reverse proxy. Registered without the `web` middleware group on purpose:
 * no session, no CSRF, no Inertia.
 */
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
