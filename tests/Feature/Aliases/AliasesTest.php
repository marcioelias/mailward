<?php

declare(strict_types=1);

use App\Actions\Aliases\DeleteAliasAction;
use App\Casts\NeverExpiresDate;
use App\Models\AuditEntry;
use App\Models\Mail\Alias;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/aliases.md. The rule that matters most is the split: the alias
 * account is a row in `alias` (BR-01) and its members are rows in
 * `forwardings` with `is_list = 1` (BR-02). Conflating the two in the data
 * layer is the defect the document exists to prevent.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain', 'deleted_mailboxes'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }

    AuditEntry::query()->delete();
});

function makeAliasRow(string $address, array $attributes = []): void
{
    DB::connection('vmail')->table('alias')->insert([
        'address' => $address,
        'name' => '',
        'accesspolicy' => '',
        'domain' => Str::afterLast($address, '@'),
        'created' => now(),
        'modified' => now(),
        'expired' => NeverExpiresDate::SENTINEL,
        'active' => 1,
        ...$attributes,
    ]);
}

/** A row in `forwardings`, whichever of the four purposes it serves. */
function makeForwarding(string $address, string $forwarding, array $flags = []): void
{
    DB::connection('vmail')->table('forwardings')->insert([
        'address' => $address,
        'forwarding' => $forwarding,
        'domain' => Str::afterLast($address, '@'),
        'dest_domain' => Str::afterLast($forwarding, '@'),
        'is_forwarding' => 0,
        'is_alias' => 0,
        'is_list' => 0,
        'is_maillist' => 0,
        'active' => 1,
        ...$flags,
    ]);
}

function makeMailboxRow(string $address, int $active = 1): void
{
    DB::connection('vmail')->table('mailbox')->insert([
        'username' => $address,
        'domain' => Str::afterLast($address, '@'),
        'password' => '{PLAIN}x',
        'active' => $active,
        'created' => now(),
        'modified' => now(),
        'expired' => NeverExpiresDate::SENTINEL,
    ]);

    // Every mailbox carries the self-referencing row iRedMail writes for it
    // (docs/02-domain.md §5). Several rules below turn on it surviving.
    makeForwarding($address, $address, ['is_forwarding' => 1]);
}

function makeAliasDomainRow(string $alias, string $target): void
{
    DB::connection('vmail')->table('alias_domain')->insert([
        'alias_domain' => $alias,
        'target_domain' => $target,
        'created' => now(),
        'modified' => now(),
        'active' => 1,
    ]);
}

describe('the listing (AC-01, AC-02)', function () {
    it('lists rows from alias only, and never a forwardings row as an alias', function () {
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
        makeMailboxRow('bob@example.test');
        makeForwarding('sales@example.test', 'bob@example.test', ['is_list' => 1]);
        makeForwarding('nickname@example.test', 'bob@example.test', ['is_alias' => 1]);

        $this->actingAs(globalAdmin())->get('/aliases')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Aliases/Index')
                ->has('aliases.data', 1)
                ->where('aliases.data.0.address', 'sales@example.test')
                ->where('aliases.data.0.members', 1)
        );
    });

    it('shows a domain admin only their own aliases, filtered in the query', function () {
        // Asserted through the paginator total rather than the rendered page:
        // the exclusion must be the SQL query's doing (AC-02).
        makeDomain('mine.test');
        makeDomain('theirs.test');
        makeAliasRow('sales@mine.test');
        makeAliasRow('sales@theirs.test');

        $this->actingAs(domainAdmin('mine.test'))->get('/aliases')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('aliases.total', 1)
                ->where('aliases.data.0.address', 'sales@mine.test')
        );
    });
});

describe('creating the account (BR-07, BR-16, BR-17, BR-20)', function () {
    it('lower cases the address before writing it (AC-03)', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => '  Sales@Example.TEST ', 'active' => true])
            ->assertRedirect();

        expect(Alias::withoutDomainScope()->find('sales@example.test'))->not->toBeNull()
            ->and(Alias::withoutDomainScope()->find('sales@example.test')?->domain)->toBe('example.test');
    });

    it('creates an alias with no member at all (AC-28)', function () {
        // A member-less alias is a valid state. The create is a single-table
        // write and members are added afterwards (BR-20).
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@example.test', 'active' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(DB::connection('vmail')->table('alias')->count())->toBe(1)
            ->and(DB::connection('vmail')->table('forwardings')->count())->toBe(0);
    });

    it('refuses a domain with no row in domain (AC-16)', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@nope.test', 'active' => true])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('alias')->count())->toBe(0)
            ->and(DB::connection('vmail')->table('forwardings')->count())->toBe(0);

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@example.test', 'active' => true])
            ->assertRedirect();

        expect(DB::connection('vmail')->table('alias')->count())->toBe(1);
    });

    it('refuses a domain that exists only as an alias domain (AC-17)', function () {
        // An alias domain has no accounts of its own, so an alias inside one
        // would be shadowed by the mapping rather than reachable through it.
        makeDomain('example.test');
        makeAliasDomainRow('alias.test', 'example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@alias.test', 'active' => true])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('alias')->count())->toBe(0);
    });

    it('permits an address that is already a mailbox, and asks no other table (AC-18)', function () {
        // Collisions are permitted: which of the two wins at delivery is a
        // property of the mail server, not of Mailward (BR-17).
        makeDomain('example.test');
        makeMailboxRow('sales@example.test');

        DB::connection('vmail')->flushQueryLog();
        DB::connection('vmail')->enableQueryLog();

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@example.test', 'active' => true])
            ->assertRedirect();

        $statements = collect(DB::connection('vmail')->getQueryLog())->pluck('query')->implode(' | ');

        expect(Alias::withoutDomainScope()->find('sales@example.test'))->not->toBeNull()
            ->and(DB::connection('vmail')->table('mailbox')->where('username', 'sales@example.test')->count())->toBe(1)
            ->and($statements)->not->toContain('"mailbox"')
            ->and($statements)->not->toContain('"alias_domain"')
            ->and($statements)->not->toContain('"forwardings"');
    });

    it('stores an arbitrary accesspolicy unchanged (AC-13)', function () {
        // Free text, VARCHAR(30), unconstrained at the schema level. Mailward
        // presents no list and rejects no value until OQ-AL-01 is answered.
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/aliases', [
            'address' => 'sales@example.test', 'accesspolicy' => 'whatever-30-chars', 'active' => true,
        ])->assertRedirect();

        expect(Alias::withoutDomainScope()->find('sales@example.test')?->accesspolicy)
            ->toBe('whatever-30-chars');
    });

    it('writes created, modified and the never-expires sentinel explicitly (AC-10)', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'sales@example.test', 'active' => true]);

        $raw = DB::connection('vmail')->table('alias')->where('address', 'sales@example.test')->first();

        expect($raw->created)->not->toBeNull()
            ->and($raw->modified)->not->toBeNull()
            ->and((string) $raw->expired)->toStartWith('9999-12-31')
            ->and((int) $raw->active)->toBe(1)
            // Expiry is tested as a comparison, never by equality against a
            // sentinel: the two drivers ship different ones (BR-08, D2).
            ->and(Alias::withoutDomainScope()->where('expired', '>', now())->count())->toBe(1);
    });
});

describe('the domain limit counts alias rows only (BR-06, BR-15)', function () {
    it('refuses a create once domain.aliases is reached (AC-04)', function () {
        makeDomain('example.test', ['aliases' => 2]);
        makeAliasRow('one@example.test');
        makeAliasRow('two@example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'three@example.test', 'active' => true])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('alias')->count())->toBe(2);
    });

    it('treats a zero limit as unlimited, not as none allowed (AC-05)', function () {
        makeDomain('example.test', ['aliases' => 0]);
        makeAliasRow('one@example.test');
        makeAliasRow('two@example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'three@example.test', 'active' => true])
            ->assertRedirect();

        expect(DB::connection('vmail')->table('alias')->count())->toBe(3);
    });

    it('ignores per-account aliases and alias domains when counting (AC-15)', function () {
        // The budget bounds `alias` rows and only those: a per-account alias
        // is a `forwardings` row and an alias domain is an `alias_domain` row.
        makeDomain('example.test', ['aliases' => 2]);
        makeAliasRow('one@example.test');
        makeMailboxRow('bob@example.test');

        foreach (['a', 'b', 'c', 'd'] as $nickname) {
            makeForwarding($nickname.'@example.test', 'bob@example.test', ['is_alias' => 1]);
        }

        makeAliasDomainRow('first.test', 'example.test');
        makeAliasDomainRow('second.test', 'example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'two@example.test', 'active' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/aliases', ['address' => 'three@example.test', 'active' => true])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('alias')->count())->toBe(2);
    });
});

describe('members live in forwardings (BR-02, BR-04, BR-21)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
        makeMailboxRow('bob@example.test');
    });

    it('writes one is_list row and nothing in alias (AC-06, AC-30)', function () {
        $this->actingAs(globalAdmin())->post('/aliases/sales@example.test/members', [
            'forwarding' => 'bob@example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::connection('vmail')->table('forwardings')
            ->where('address', 'sales@example.test')
            ->where('forwarding', 'bob@example.test')
            ->first();

        expect($row)->not->toBeNull()
            ->and((int) $row->is_list)->toBe(1)
            ->and((int) $row->is_forwarding)->toBe(0)
            ->and((int) $row->is_alias)->toBe(0)
            ->and((int) $row->is_maillist)->toBe(0)
            // Always the integer 1, and never updated afterwards (BR-21).
            ->and((int) $row->active)->toBe(1)
            ->and(DB::connection('vmail')->table('alias')->count())->toBe(1);
    });

    it('refuses the same member twice and keeps one row (AC-07)', function () {
        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'bob@example.test']);

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'bob@example.test'])
            ->assertSessionHasErrors('forwarding');

        expect(DB::connection('vmail')->table('forwardings')
            ->where('address', 'sales@example.test')
            ->where('forwarding', 'bob@example.test')
            ->count())->toBe(1);
    });

    it('removes only the is_list row, leaving the mailbox self-forwarding (AC-08)', function () {
        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'bob@example.test']);

        $this->actingAs(globalAdmin())
            ->delete('/aliases/sales@example.test/members/bob@example.test')
            ->assertRedirect();

        expect(DB::connection('vmail')->table('forwardings')
            ->where('address', 'sales@example.test')->count())->toBe(0)
            // The row without which the mailbox silently stops receiving mail.
            ->and(DB::connection('vmail')->table('forwardings')
                ->where('address', 'bob@example.test')
                ->where('forwarding', 'bob@example.test')
                ->where('is_forwarding', 1)->count())->toBe(1);
    });

    it('allows removing the last member and flags the empty alias (AC-29)', function () {
        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'bob@example.test']);

        $this->actingAs(globalAdmin())
            ->delete('/aliases/sales@example.test/members/bob@example.test')
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(Alias::withoutDomainScope()->find('sales@example.test'))->not->toBeNull();

        $this->actingAs(globalAdmin())->get('/aliases')->assertInertia(
            fn (AssertableInertia $page) => $page->where('aliases.data.0.members', 0)
        );

        $this->actingAs(globalAdmin())->get('/aliases/sales@example.test/edit')->assertInertia(
            fn (AssertableInertia $page) => $page->component('Aliases/Form')->has('members', 0)
        );
    });

    it('lists a member row written outside Mailward with active = 0 (AC-31)', function () {
        makeForwarding('sales@example.test', 'bob@example.test', ['is_list' => 1, 'active' => 0]);

        $this->actingAs(globalAdmin())->get('/aliases/sales@example.test/edit')->assertInertia(
            fn (AssertableInertia $page) => $page->has('members', 1)
                ->where('members.0', 'bob@example.test')
        );

        // Nothing on the page offers to enable it, and nothing wrote it.
        expect((int) DB::connection('vmail')->table('forwardings')
            ->where('address', 'sales@example.test')->value('active'))->toBe(0);
    });

    it('exposes no route that updates a member row (AC-30)', function () {
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'aliases/') && str_contains($route->uri(), 'members'))
            ->flatMap(fn ($route): array => $route->methods())
            ->unique()->values()->all();

        expect($methods)->not->toContain('PUT')
            ->and($methods)->not->toContain('PATCH');
    });
});

describe('a member address is checked for format always and for existence when local (BR-19)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
    });

    it('refuses a local address that exists nowhere (AC-21)', function () {
        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'ghost@example.test'])
            ->assertSessionHasErrors('forwarding');

        expect(DB::connection('vmail')->table('forwardings')->count())->toBe(0);
    });

    it('accepts an external address and still refuses a malformed one (AC-22)', function () {
        // Restricting the destination is what a hosting provider does to a
        // customer; administrators here are staff (docs/00-overview.md §3).
        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'anyone@external.example'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'not-an-address'])
            ->assertSessionHasErrors('forwarding');

        expect(DB::connection('vmail')->table('forwardings')->count())->toBe(1);
    });

    it('accepts a mailbox, an alias and a per-account alias, consulting all three (AC-23)', function () {
        makeMailboxRow('bob@example.test');
        makeAliasRow('team@example.test');
        makeForwarding('nickname@example.test', 'bob@example.test', ['is_alias' => 1]);

        foreach (['bob@example.test', 'team@example.test'] as $member) {
            $this->actingAs(globalAdmin())
                ->post('/aliases/sales@example.test/members', ['forwarding' => $member])
                ->assertSessionHasNoErrors();
        }

        DB::connection('vmail')->flushQueryLog();
        DB::connection('vmail')->enableQueryLog();

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'nickname@example.test'])
            ->assertSessionHasNoErrors();

        /*
         * Identifier quoting is a driver detail — PostgreSQL uses double
         * quotes and MySQL backticks — and this test is about which tables
         * were consulted, not how they were spelled. Stripping both keeps the
         * assertion true on either driver.
         */
        $statements = collect(DB::connection('vmail')->getQueryLog())
            ->pluck('query')
            ->map(fn (string $sql): string => str_replace(['"', '`'], '', $sql));

        // Any one of the three satisfies the check, so all three are consulted
        // for the address that only the last one matches — and every query is
        // anchored on an address column, never on a flag alone (BR-05, BR-19).
        expect($statements->filter(fn (string $sql): bool => str_contains($sql, 'mailbox') && str_contains($sql, 'username'))->count())->toBeGreaterThan(0)
            ->and($statements->filter(fn (string $sql): bool => str_contains($sql, 'from alias') && str_contains($sql, 'address'))->count())->toBeGreaterThan(0)
            ->and($statements->filter(fn (string $sql): bool => str_contains($sql, 'forwardings') && str_contains($sql, 'address'))->count())->toBeGreaterThan(0)
            ->and(DB::connection('vmail')->table('forwardings')->where('address', 'sales@example.test')->count())->toBe(3);
    });

    it('treats a domain that exists only as an alias domain as external (AC-24)', function () {
        // There is no row to check the member against, and refusing it would
        // reject an address the server does deliver.
        makeAliasDomainRow('list.test', 'example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'sales@list.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(DB::connection('vmail')->table('forwardings')
            ->where('forwarding', 'sales@list.test')->count())->toBe(1);
    });

    it('permits an alias naming itself, and two aliases naming each other (AC-25)', function () {
        // No graph walk: it could not see loops formed through per-account
        // aliases and external hops, so the incomplete guarantee is not made.
        makeAliasRow('all@example.test');

        $this->actingAs(globalAdmin())
            ->post('/aliases/all@example.test/members', ['forwarding' => 'all@example.test'])
            ->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/aliases/all@example.test/members', ['forwarding' => 'sales@example.test'])
            ->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'all@example.test'])
            ->assertSessionHasNoErrors();

        expect(DB::connection('vmail')->table('forwardings')->where('is_list', 1)->count())->toBe(3);
    });

    it('accepts a local member whose account is disabled (AC-26)', function () {
        // Existence is checked, liveness is not: a disabled member is a state
        // an administrator chose, not a typo.
        makeMailboxRow('dormant@example.test', active: 0);

        $this->actingAs(globalAdmin())
            ->post('/aliases/sales@example.test/members', ['forwarding' => 'dormant@example.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(DB::connection('vmail')->table('forwardings')
            ->where('forwarding', 'dormant@example.test')->where('is_list', 1)->count())->toBe(1);
    });
});

describe('deletion is a cascade in one transaction (BR-10, BR-14, BR-18)', function () {
    it('removes the alias and its members, and no row of another kind (AC-09)', function () {
        makeDomain('example.test');
        makeAliasRow('sales@example.test');

        foreach (['a', 'b', 'c'] as $member) {
            makeForwarding('sales@example.test', $member.'@example.test', ['is_list' => 1]);
        }

        // Same address, different purpose: a per-account alias of a mailbox
        // that happens to share the name (collisions are permitted, BR-17).
        makeForwarding('sales@example.test', 'elsewhere@example.test', ['is_alias' => 1]);

        $this->actingAs(globalAdmin())->delete('/aliases/sales@example.test')->assertRedirect();

        expect(Alias::withoutDomainScope()->find('sales@example.test'))->toBeNull()
            ->and(DB::connection('vmail')->table('forwardings')->where('is_list', 1)->count())->toBe(0)
            ->and(DB::connection('vmail')->table('forwardings')->where('is_alias', 1)->count())->toBe(1);
    });

    it('removes the rows that pointed at the alias, and nothing else (AC-19)', function () {
        // Without this the address survives as a live routing target after the
        // alias is gone: every listing looks right and mail is routed to an
        // address that no longer accepts it (BR-18).
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
        makeAliasRow('all@example.test');
        makeMailboxRow('bob@example.test');

        makeForwarding('bob@example.test', 'sales@example.test', ['is_forwarding' => 1]);
        makeForwarding('all@example.test', 'sales@example.test', ['is_list' => 1]);
        makeForwarding('announce@example.test', 'sales@example.test', ['is_maillist' => 1]);

        $this->actingAs(globalAdmin())->delete('/aliases/sales@example.test')->assertRedirect();

        expect(DB::connection('vmail')->table('forwardings')
            ->where('forwarding', 'sales@example.test')->where('is_forwarding', 1)->count())->toBe(0)
            ->and(DB::connection('vmail')->table('forwardings')
                ->where('forwarding', 'sales@example.test')->where('is_list', 1)->count())->toBe(0)
            // Mailing lists are unmodelled in v1 and are never touched.
            ->and(DB::connection('vmail')->table('forwardings')
                ->where('is_maillist', 1)->count())->toBe(1)
            // The other accounts survive, including the mailbox's own row.
            ->and(DB::connection('vmail')->table('mailbox')->where('username', 'bob@example.test')->count())->toBe(1)
            ->and(DB::connection('vmail')->table('forwardings')
                ->where('address', 'bob@example.test')->where('forwarding', 'bob@example.test')->count())->toBe(1)
            ->and(Alias::withoutDomainScope()->find('all@example.test'))->not->toBeNull();
    });

    it('runs every delete in one vmail transaction, and re-runs harmlessly (AC-20)', function () {
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
        makeForwarding('sales@example.test', 'bob@external.example', ['is_list' => 1]);
        makeForwarding('other@example.test', 'sales@example.test', ['is_forwarding' => 1]);

        $alias = Alias::withoutDomainScope()->find('sales@example.test');

        $transactions = 0;
        Event::listen(function (TransactionBeginning $event) use (&$transactions): void {
            if ($event->connectionName === 'vmail') {
                $transactions++;
            }
        });

        $this->actingAs(globalAdmin())->delete('/aliases/sales@example.test')->assertRedirect();

        // The members, the inbound rows and the alias row itself: one
        // transaction for the whole cascade, not one per table.
        expect($transactions)->toBe(1);

        // The same cascade again, against rows that are already gone. It
        // completes without error and removes nothing further.
        app(DeleteAliasAction::class)->handle($alias);

        expect(DB::connection('vmail')->table('forwardings')->count())->toBe(0)
            ->and(DB::connection('vmail')->table('alias')->count())->toBe(0);
    });

    it('writes no deletion record and keeps the address in the log (AC-14)', function () {
        // An alias owns no mail storage, so iRedMail's removal cron has
        // nothing to do for it, and no Mailward table is keyed by an alias
        // address (BR-14).
        makeDomain('example.test');
        makeAliasRow('sales@example.test');
        makeForwarding('sales@example.test', 'a@external.example', ['is_list' => 1]);
        makeForwarding('sales@example.test', 'b@external.example', ['is_list' => 1]);

        $this->actingAs(globalAdmin())->delete('/aliases/sales@example.test')->assertRedirect();

        $entry = AuditEntry::query()->where('event', 'deleted')->latest('id')->first();

        expect(DB::connection('vmail')->table('deleted_mailboxes')->count())->toBe(0)
            ->and($entry)->not->toBeNull()
            ->and($entry->properties['identifier'] ?? null)->toBe('sales@example.test')
            ->and($entry->properties['old']['removed']['members'] ?? null)->toBe(2);
    });
});

describe('updating the account (AC-12)', function () {
    it('disables an alias with the integer, never a boolean binding', function () {
        // PostgreSQL rejects a PHP bool against INT2 where MySQL accepts it
        // (matrix D4), so this must pass on both drivers.
        makeDomain('example.test');
        makeAliasRow('sales@example.test');

        $this->actingAs(globalAdmin())->put('/aliases/sales@example.test', [
            'name' => 'Sales', 'accesspolicy' => '', 'active' => false,
        ])->assertRedirect();

        expect((int) DB::connection('vmail')->table('alias')
            ->where('address', 'sales@example.test')->value('active'))->toBe(0);
    });
});

describe('authorisation is decided on the alias domain (BR-12, BR-19)', function () {
    it('refuses a domain admin an alias outside their scope, and records it (AC-11)', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');
        makeAliasRow('sales@theirs.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->get('/aliases/sales@theirs.test/edit')
            ->assertForbidden();

        $entry = AuditEntry::query()->where('event', 'authorization-failed')->latest('id')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->actor)->toBe('da@example.test')
            ->and($entry->subject_id)->toBe('sales@theirs.test');
    });

    it('lets a domain admin add a member from a domain they do not administer (AC-27)', function () {
        // The member's domain is not scoped: authorisation is decided on the
        // alias account's own domain and never on a destination.
        makeDomain('mine.test');
        makeDomain('other.test');
        makeAliasRow('sales@mine.test');
        makeMailboxRow('someone@other.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->post('/aliases/sales@mine.test/members', ['forwarding' => 'someone@other.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(DB::connection('vmail')->table('forwardings')
            ->where('address', 'sales@mine.test')->where('is_list', 1)->count())->toBe(1);
    });

    it('refuses the same operation on an alias outside their scope (AC-27)', function () {
        makeDomain('mine.test');
        makeDomain('other.test');
        makeAliasRow('sales@other.test');
        makeMailboxRow('someone@other.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->post('/aliases/sales@other.test/members', ['forwarding' => 'someone@other.test'])
            ->assertForbidden();

        expect(DB::connection('vmail')->table('forwardings')->where('is_list', 1)->count())->toBe(0);
    });

    it('refuses a domain admin deleting an alias outside their scope', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');
        makeAliasRow('sales@theirs.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->delete('/aliases/sales@theirs.test')->assertForbidden();

        expect(Alias::withoutDomainScope()->find('sales@theirs.test'))->not->toBeNull();
    });

    it('refuses a create in a domain the actor does not administer', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->post('/aliases', ['address' => 'sales@theirs.test', 'active' => true])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('alias')->count())->toBe(0);
    });
});
