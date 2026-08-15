<?php

declare(strict_types=1);

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

/*
 * Everything is behind authentication. An unauthenticated request to a panel
 * route is redirected to the login form and the target is never rendered
 * (docs/features/authentication.md BR-18).
 *
 * Deny by default is the posture, not a convention: a route added without a
 * guard must fail closed. Fortify's own /login and /logout are registered by
 * the package with the guest and auth middleware it needs.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/', StatusController::class)->name('status');
});
