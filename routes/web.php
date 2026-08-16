<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AliasController;
use App\Http\Controllers\AliasDomainController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainAdminController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxRoutingController;
use App\Http\Controllers\StatusController;
use App\Http\Middleware\EnsureStillAnAdministrator;
use Illuminate\Support\Facades\Route;

/*
 * Everything is behind authentication, and the administrator gate is re-checked
 * on every request rather than only at login — a revocation takes effect by the
 * revoked account's next click (docs/features/authentication.md BR-18, BR-21).
 *
 * Deny by default is the posture, not a convention: a route added without a
 * guard must fail closed.
 */
Route::middleware(['auth', EnsureStillAnAdministrator::class])->group(function (): void {
    /*
     * `/dashboard` is the path the specification names; `/` redirects to it
     * rather than duplicating the route, so there is one canonical URL for the
     * screen and the landing page cannot drift from it.
     */
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // The connection check that predates the dashboard. Kept because it is the
    // screen that says *why* nothing works, when nothing works.
    Route::get('/status', StatusController::class)->name('status');

    /*
     * The acting administrator's own account, exempt from the domain scope:
     * their mailbox may sit in a domain they do not administer, and they must
     * always be able to rotate their own mail password (BR-23).
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
     * The same authority shape as domains, and for the same reason: an alias
     * domain adds a name to the mail server's namespace (alias-domains.md BR-12).
     */
    Route::get('/alias-domains', [AliasDomainController::class, 'index'])->name('alias-domains.index');
    Route::get('/alias-domains/create', [AliasDomainController::class, 'create'])->name('alias-domains.create');
    Route::post('/alias-domains', [AliasDomainController::class, 'store'])->name('alias-domains.store');
    Route::get('/alias-domains/{aliasDomain}/edit', [AliasDomainController::class, 'edit'])->name('alias-domains.edit');
    Route::put('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'update'])->name('alias-domains.update');
    Route::post('/alias-domains/{aliasDomain}/active', [AliasDomainController::class, 'setActive'])->name('alias-domains.active');
    Route::delete('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'destroy'])->name('alias-domains.destroy');

    /*
     * Mailboxes and aliases are the *contents* of a domain rather than the
     * domain record, so a domain admin may write them within their scope.
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

    /*
     * Per-user aliases and forwardings — the contents of one account, so they
     * live under it. Two concepts sharing one table, kept as two sets of
     * endpoints (docs/02-domain.md §5).
     */
    Route::get('/mailboxes/{mailbox}/routing', [MailboxRoutingController::class, 'index'])->name('mailboxes.routing');
    Route::post('/mailboxes/{mailbox}/aliases', [MailboxRoutingController::class, 'storeAlias'])->name('mailboxes.aliases.store');
    Route::delete('/mailboxes/{mailbox}/aliases/{alias}', [MailboxRoutingController::class, 'destroyAlias'])->name('mailboxes.aliases.destroy');
    Route::post('/mailboxes/{mailbox}/forwardings', [MailboxRoutingController::class, 'storeForwarding'])->name('mailboxes.forwardings.store');
    Route::delete('/mailboxes/{mailbox}/forwardings/{forwarding}', [MailboxRoutingController::class, 'destroyForwarding'])->name('mailboxes.forwardings.destroy');

    /*
     * A standalone alias and its members are two tables and two sets of
     * endpoints, and there is deliberately no route that updates a member row:
     * members are created and removed, never edited (aliases.md BR-21).
     */
    Route::get('/aliases', [AliasController::class, 'index'])->name('aliases.index');
    Route::get('/aliases/create', [AliasController::class, 'create'])->name('aliases.create');
    Route::post('/aliases', [AliasController::class, 'store'])->name('aliases.store');
    Route::get('/aliases/{alias}/edit', [AliasController::class, 'edit'])->name('aliases.edit');
    Route::put('/aliases/{alias}', [AliasController::class, 'update'])->name('aliases.update');
    Route::delete('/aliases/{alias}', [AliasController::class, 'destroy'])->name('aliases.destroy');
    Route::post('/aliases/{alias}/members', [AliasController::class, 'storeMember'])->name('aliases.members.store');
    Route::delete('/aliases/{alias}/members/{forwarding}', [AliasController::class, 'destroyMember'])->name('aliases.members.destroy');

    /*
     * Administrators. `{address}` is a plain string rather than a bound model:
     * the controller resolves it unscoped, so an actor without authority gets a
     * 403 that says "you may not" rather than a 404 that says "no such
     * account" — the account does exist, and pretending otherwise would be a
     * lie the actor can disprove.
     */
    Route::get('/admins', [DomainAdminController::class, 'index'])->name('admins.index');
    Route::get('/admins/create', [DomainAdminController::class, 'create'])->name('admins.create');
    Route::post('/admins', [DomainAdminController::class, 'store'])->name('admins.store');
    Route::get('/admins/{address}/edit', [DomainAdminController::class, 'edit'])->name('admins.edit');
    Route::delete('/admins/{address}', [DomainAdminController::class, 'destroy'])->name('admins.destroy');
    Route::post('/admins/{address}/global', [DomainAdminController::class, 'storeGlobal'])->name('admins.global.store');
    Route::delete('/admins/{address}/global', [DomainAdminController::class, 'destroyGlobal'])->name('admins.global.destroy');
    Route::post('/admins/{address}/domains', [DomainAdminController::class, 'storeDomain'])->name('admins.domains.store');
    Route::delete('/admins/{address}/domains/{domain}', [DomainAdminController::class, 'destroyDomain'])->name('admins.domains.destroy');

    // Global admins only, enforced by the policy (audit-log.md BR-16).
    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
});
