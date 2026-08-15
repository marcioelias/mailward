<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;

it('renders the status page with the mail backend it found', function () {
    $this->get('/')->assertOk()->assertInertia(
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

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Status')
            ->where('backend.connected', false)
            ->whereNot('backend.error', null)
    );
});
