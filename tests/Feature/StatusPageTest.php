<?php

declare(strict_types=1);

use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;

/** A signed-in administrator, built without touching the mail database. */
function signedInAdministrator(): Mailbox
{
    return new Mailbox(['username' => 'admin@example.test', 'isglobaladmin' => true, 'active' => true]);
}

it('renders the status page with the mail backend it found', function () {
    $this->actingAs(signedInAdministrator())
        ->get('/status')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Status')
                ->where('backend.connected', true)
                ->where('backend.missingTables', [])
                ->where('backend.driver', config('database.connections.vmail.driver'))
                ->has('backend.counts.domain')
                ->has('backend.counts.mailbox')
        );
});

it('reports a clear failure instead of erroring when the backend is unreachable', function () {
    // docs/decisions/0001-sql-backend-only.md requires detecting the backend
    // and saying so plainly, rather than failing obscurely later.
    Config::set('database.connections.vmail.database', 'a_database_that_does_not_exist');
    Config::set('database.connections.vmail.host', '127.0.0.1');
    Config::set('database.connections.vmail.port', '1');

    $this->actingAs(signedInAdministrator())
        ->get('/status')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Status')
                ->where('backend.connected', false)
                ->whereNot('backend.error', null)
        );
});

it('sends a guest to the login form instead of rendering the panel', function () {
    // docs/features/authentication.md BR-18. Before this guard the page
    // exposed how many domains and mailboxes the server holds, to anyone.
    auth()->logout();

    $this->get('/status')->assertRedirect('/login');
});
