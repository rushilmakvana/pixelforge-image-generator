<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Signed by Pixelforge, not by a session: excluded from CSRF in bootstrap/app.php.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generate');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');
    Route::get('history', [GenerationController::class, 'index'])->name('history');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits');
    Route::post('credits', [CreditsController::class, 'store'])->middleware('throttle:10,1')->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->middleware('can:admin')->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
