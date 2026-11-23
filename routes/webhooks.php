<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/**
 * Pixelforge webhooks.
 *
 * This file is loaded by bootstrap/app.php outside the `web` middleware group, so there
 * is no session, cookie or CSRF layer. Pixelforge has no CSRF token, and the signature
 * over the raw body is the authentication.
 */
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
