<?php

declare(strict_types=1);

use App\Http\Controllers\DomainController;
use App\Http\Controllers\StatusController;
use App\Http\Middleware\EnsureStillAnAdministrator;
use Illuminate\Support\Facades\Route;

/*
 * Everything is behind authentication. An unauthenticated request to a panel
 * route is redirected to the login form and the target is never rendered
 * (docs/features/authentication.md BR-18).
 *
 * Deny by default is the posture, not a convention: a route added without a
 * guard must fail closed.
 */
Route::middleware(['auth', EnsureStillAnAdministrator::class])->group(function (): void {
    Route::get('/', StatusController::class)->name('status');

    /*
     * Only the listing is reachable by a domain admin, and it is scoped in the
     * query. Every other route here is global-admin only, enforced by the
     * policy rather than by the route (docs/features/domains.md BR-19).
     */
    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('/domains/create', [DomainController::class, 'create'])->name('domains.create');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}/edit', [DomainController::class, 'edit'])->name('domains.edit');
    Route::put('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::post('/domains/{domain}/active', [DomainController::class, 'setActive'])->name('domains.active');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
});
