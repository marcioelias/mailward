<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AliasDomainController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\MailboxController;
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
     * The acting administrator's own account, exempt from the domain scope:
     * their mailbox may sit in a domain they do not administer, and they must
     * always be able to rotate their own mail password
     * (docs/features/authentication.md BR-23).
     */
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

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

    /*
     * Same shape as domains, and for the same reason: an alias domain adds a
     * name to the mail server's namespace, so every write is global-admin only
     * (docs/features/alias-domains.md BR-12). Visibility is scoped on
     * target_domain, which is the only column here naming a row in `domain`.
     */
    Route::get('/alias-domains', [AliasDomainController::class, 'index'])->name('alias-domains.index');
    Route::get('/alias-domains/create', [AliasDomainController::class, 'create'])->name('alias-domains.create');
    Route::post('/alias-domains', [AliasDomainController::class, 'store'])->name('alias-domains.store');
    Route::get('/alias-domains/{aliasDomain}/edit', [AliasDomainController::class, 'edit'])->name('alias-domains.edit');
    Route::put('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'update'])->name('alias-domains.update');
    Route::post('/alias-domains/{aliasDomain}/active', [AliasDomainController::class, 'setActive'])->name('alias-domains.active');
    Route::delete('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'destroy'])->name('alias-domains.destroy');

    /*
     * Mailboxes are the contents of a domain rather than the domain record, so
     * a domain admin may write them within their scope. Every route is scoped
     * by the model, and the policy is the second barrier.
     */
    Route::get('/mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
    Route::get('/mailboxes/create', [MailboxController::class, 'create'])->name('mailboxes.create');
    Route::post('/mailboxes', [MailboxController::class, 'store'])->name('mailboxes.store');
    Route::get('/mailboxes/{mailbox}/edit', [MailboxController::class, 'edit'])->name('mailboxes.edit');
    Route::put('/mailboxes/{mailbox}', [MailboxController::class, 'update'])->name('mailboxes.update');
    Route::get('/mailboxes/{mailbox}/password', [MailboxController::class, 'editPassword'])->name('mailboxes.password.edit');
    Route::put('/mailboxes/{mailbox}/password', [MailboxController::class, 'updatePassword'])->name('mailboxes.password');
    Route::post('/mailboxes/{mailbox}/active', [MailboxController::class, 'setActive'])->name('mailboxes.active');
    Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->name('mailboxes.destroy');
});
