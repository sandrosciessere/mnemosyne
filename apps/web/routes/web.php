<?php

use App\Http\Controllers\Admin\LibraryAdminController;
use App\Http\Controllers\Admin\ProcessingController;
use App\Http\Controllers\Admin\RetrievalAdminController;
use App\Http\Controllers\Admin\SubmissionAdminController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Library\BookDownloadController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\Library\SubmissionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::get('search', fn () => Inertia::render('search'))->name('search');
    Route::get('analyses', fn () => Inertia::render('analyses'))->name('analyses');

    Route::get('library', [LibraryController::class, 'index'])->name('library');
    Route::get('library/books/{asset}/download', BookDownloadController::class)
        ->name('library.books.download');

    Route::get('library/submissions', [SubmissionController::class, 'index'])
        ->name('library.submissions.index');
    Route::get('library/submissions/create', [SubmissionController::class, 'create'])
        ->name('library.submissions.create');
    Route::post('library/submissions', [SubmissionController::class, 'store'])
        ->middleware('throttle:submissions')
        ->name('library.submissions.store');
    Route::get('library/submissions/{submission}', [SubmissionController::class, 'show'])
        ->name('library.submissions.show');
    Route::post('library/submissions/{submission}/cancel', [SubmissionController::class, 'cancel'])
        ->name('library.submissions.cancel');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('processing', [ProcessingController::class, 'index'])->name('processing');
        Route::get('processing/runs', [ProcessingController::class, 'runs'])->name('processing.runs');
        Route::get('processing/runs/{run}', [ProcessingController::class, 'show'])->name('processing.runs.show');
        Route::post('processing/runs/{run}/retry', [ProcessingController::class, 'retry'])->name('processing.runs.retry');
        Route::post('processing/runs/{run}/cancel', [ProcessingController::class, 'cancel'])->name('processing.runs.cancel');
        Route::patch('processing/runs/{run}/priority', [ProcessingController::class, 'priority'])->name('processing.runs.priority');
        Route::post('processing/runs/{run}/override', [ProcessingController::class, 'override'])->name('processing.runs.override');
        Route::post('processing/runs/{run}/pause', [ProcessingController::class, 'pause'])->name('processing.runs.pause');
        Route::post('processing/runs/{run}/resume', [ProcessingController::class, 'resume'])->name('processing.runs.resume');
        Route::post('processing/runs/{run}/mark-unsupported', [ProcessingController::class, 'markUnsupported'])->name('processing.runs.mark-unsupported');
        Route::post('processing/pause', [ProcessingController::class, 'pauseGlobal'])->name('processing.pause');
        Route::post('processing/resume', [ProcessingController::class, 'resumeGlobal'])->name('processing.resume');

        Route::get('submissions', [SubmissionAdminController::class, 'index'])->name('submissions');
        Route::post('submissions/{submission}/approve', [SubmissionAdminController::class, 'approve'])->name('submissions.approve');
        Route::post('submissions/{submission}/reject', [SubmissionAdminController::class, 'reject'])->name('submissions.reject');

        Route::get('retrieval', [RetrievalAdminController::class, 'index'])->name('retrieval');
        Route::get('retrieval/debugger', [RetrievalAdminController::class, 'debugger'])->name('retrieval.debugger');

        Route::get('library', [LibraryAdminController::class, 'index'])->name('library');
        Route::get('library/works/{work}', [LibraryAdminController::class, 'work'])->name('library.work');
        Route::get('library/assets/{asset}', [LibraryAdminController::class, 'asset'])->name('library.asset');

        Route::get('users', fn () => Inertia::render('admin/users'))->name('users');
        Route::get('system', [SystemSettingsController::class, 'index'])->name('system');
        Route::put('system/settings', [SystemSettingsController::class, 'update'])->name('system.settings');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
