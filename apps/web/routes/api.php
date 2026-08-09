<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned REST API. This is (and will remain) the canonical application
 * interface; MCP and other integrations are adapters on top of it.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'mnemosyne-api',
            'api_version' => 'v1',
        ]);
    })->name('health');

    Route::get('user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum')->name('user');
});
