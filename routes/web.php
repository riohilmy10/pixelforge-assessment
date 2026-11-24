<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard = generator form + recent generations.
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');

    // Live quote for the form. POST because a 4,000-character prompt does not fit safely
    // in a query string. Debounced on the client; never creates anything.
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1,estimates')
        ->name('generations.estimate');

    // Create a generation. The provider call itself happens in a queued job.
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1,submissions')
        ->name('generations.store');

    // Full history, paginated newest first.
    Route::get('generations', [GenerationController::class, 'history'])->name('generations.index');

    // Wallet: current balance, fake top-ups and the credit ledger.
    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/purchase', [CreditController::class, 'purchase'])
        ->middleware('throttle:10,1,purchases')
        ->name('credits.purchase');
});

// Operator report: every customer's spend, provider cost and profit, reconciled against
// Pixelforge's own /v1/usage for the selected UTC days.
Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
    Route::get('admin/report', [AdminReportController::class, 'index'])->name('admin.report');
});

require __DIR__.'/settings.php';
