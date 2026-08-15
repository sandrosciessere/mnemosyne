<?php

use App\Http\Controllers\Api\V1\Admin\AdminIngestionRunApiController;
use App\Http\Controllers\Api\V1\Admin\AdminSubmissionApiController;
use App\Http\Controllers\Api\V1\AnswerApiController;
use App\Http\Controllers\Api\V1\ConversationApiController;
use App\Http\Controllers\Api\V1\RetrievalSearchController;
use App\Http\Controllers\Api\V1\SubmissionApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned REST API. This is (and will remain) the canonical application
 * interface; MCP and other integrations are adapters on top of it. Admin
 * endpoints live under /v1/admin and are NEVER exposed through MCP.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'mnemosyne-api',
            'api_version' => 'v1',
        ]);
    })->name('health');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', function (Request $request) {
            return $request->user();
        })->name('user');

        Route::get('submissions', [SubmissionApiController::class, 'index'])->name('submissions.index');
        Route::post('submissions', [SubmissionApiController::class, 'store'])
            ->middleware('throttle:submissions')
            ->name('submissions.store');
        Route::get('submissions/{submission}', [SubmissionApiController::class, 'show'])->name('submissions.show');

        Route::post('answers', [AnswerApiController::class, 'store'])
            ->middleware('throttle:answers')
            ->name('answers.store');
        Route::get('answers/{answer}', [AnswerApiController::class, 'show'])->name('answers.show');
        Route::get('answers/{answer}/evidence/{evidenceKey}', [AnswerApiController::class, 'evidence'])
            ->name('answers.evidence');
        Route::get('conversations', [ConversationApiController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationApiController::class, 'show'])->name('conversations.show');

        Route::post('retrieval/search', [RetrievalSearchController::class, 'search'])
            ->middleware('throttle:retrieval')
            ->name('retrieval.search');
        Route::get('retrieval/chunks/{chunk}/neighbors', [RetrievalSearchController::class, 'neighbors'])
            ->middleware('throttle:retrieval-neighbors')
            ->name('retrieval.neighbors');

        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('submissions', [AdminSubmissionApiController::class, 'index'])->name('submissions.index');
            Route::post('submissions/{submission}/approve', [AdminSubmissionApiController::class, 'approve'])->name('submissions.approve');
            Route::post('submissions/{submission}/reject', [AdminSubmissionApiController::class, 'reject'])->name('submissions.reject');

            Route::get('processing/overview', [AdminIngestionRunApiController::class, 'overview'])->name('processing.overview');
            Route::get('ingestion-runs', [AdminIngestionRunApiController::class, 'index'])->name('ingestion-runs.index');
            Route::get('ingestion-runs/{run}', [AdminIngestionRunApiController::class, 'show'])->name('ingestion-runs.show');
            Route::post('ingestion-runs/{run}/retry', [AdminIngestionRunApiController::class, 'retry'])->name('ingestion-runs.retry');
            Route::post('ingestion-runs/{run}/cancel', [AdminIngestionRunApiController::class, 'cancel'])->name('ingestion-runs.cancel');
            Route::patch('ingestion-runs/{run}/priority', [AdminIngestionRunApiController::class, 'priority'])->name('ingestion-runs.priority');
            Route::post('ingestion-runs/{run}/pause', [AdminIngestionRunApiController::class, 'pause'])->name('ingestion-runs.pause');
            Route::post('ingestion-runs/{run}/resume', [AdminIngestionRunApiController::class, 'resume'])->name('ingestion-runs.resume');
            Route::post('ingestion-runs/{run}/mark-unsupported', [AdminIngestionRunApiController::class, 'markUnsupported'])->name('ingestion-runs.mark-unsupported');
            Route::post('processing/pause', [AdminIngestionRunApiController::class, 'pauseGlobal'])->name('processing.pause');
            Route::post('processing/resume', [AdminIngestionRunApiController::class, 'resumeGlobal'])->name('processing.resume');
        });
    });
});
