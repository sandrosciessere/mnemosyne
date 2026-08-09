<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::get('library', fn () => Inertia::render('library'))->name('library');
    Route::get('search', fn () => Inertia::render('search'))->name('search');
    Route::get('analyses', fn () => Inertia::render('analyses'))->name('analyses');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('processing', fn () => Inertia::render('admin/processing'))->name('processing');
        Route::get('users', fn () => Inertia::render('admin/users'))->name('users');
        Route::get('system', fn () => Inertia::render('admin/system'))->name('system');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
