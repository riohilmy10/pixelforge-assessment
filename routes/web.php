<?php

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
});

require __DIR__.'/settings.php';
