<?php

declare(strict_types=1);

use App\Casts\NeverExpiresDate;
use App\Models\AuditEntry;
use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/mailbox-aliases-forwardings.md.
 *
 * Two concepts sharing one physical table, and the two rules worth breaking a
 * build over are BR-06 — the self-referencing row is never listed and never
 * removed — and BR-18, which decides what a forwarding may point at.
 *
 * The column direction these tests assert for an `is_alias` row (`address` is
 * the alias, `forwarding` is the account) is OQ-A1, read off BR-18 and AC-22
 * rather than off an install. See AddMailboxAliasAction.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

/**
 * An account and the row that makes it receive mail. Written directly rather
 * than through the mailbox endpoint: the self-referencing row of BR-06 is the
 * subject of half of these tests, so it is put there explicitly.
 */
function routingMailbox(string $address): Mailbox
{
    $domain = Str::afterLast($address, '@');

    $mailbox = Mailbox::withoutDomainScope()->create([
        'username' => $address,
        'password' => '{PLAIN}irrelevant',
        'name' => $address,
        'domain' => $domain,
        'quota' => 1024,
        'storagebasedirectory' => '/var/vmail',
        'storagenode' => 'vmail1',
        'maildir' => $domain.'/'.Str::before($address, '@').'/',
        'created' => now(),
        'modified' => now(),
        'passwordlastchange' => now(),
        'expired' => NeverExpiresDate::SENTINEL,
        'active' => true,
    ]);

    DB::connection('vmail')->table('forwardings')->insert([
        'address' => $address,
        'forwarding' => $address,
        'domain' => $domain,
        'dest_domain' => $domain,
        'is_forwarding' => 1,
        'is_alias' => 0,
        'is_list' => 0,
        'is_maillist' => 0,
        'active' => 1,
    ]);

    return $mailbox;
}

function routingRow(string $address, string $forwarding): ?object
{
    return DB::connection('vmail')->table('forwardings')
        ->where('address', $address)
        ->where('forwarding', $forwarding)
        ->first();
}

describe('what a row looks like (AC-01, AC-02, AC-06, AC-27)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('writes an alias with exactly one discriminator set', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@example.test'])
            ->assertRedirect();

        $row = routingRow('sales@example.test', 'joao@example.test');

        expect($row)->not->toBeNull()
            ->and((int) $row->is_alias)->toBe(1)
            ->and((int) $row->is_forwarding)->toBe(0)
            ->and((int) $row->is_list)->toBe(0)
            ->and((int) $row->is_maillist)->toBe(0)
            ->and((int) $row->active)->toBe(1);
    });

    it('writes a forwarding with exactly one discriminator set', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'someone@external.example'])
            ->assertRedirect();

        $row = routingRow('joao@example.test', 'someone@external.example');

        expect($row)->not->toBeNull()
            ->and((int) $row->is_forwarding)->toBe(1)
            ->and((int) $row->is_alias)->toBe(0)
            ->and((int) $row->is_list)->toBe(0)
            ->and((int) $row->is_maillist)->toBe(0)
            ->and((int) $row->active)->toBe(1);
    });

    it('lists aliases and forwardings as two separate things (BR-01)', function () {
        // One table, two concepts — never merged into a single list
        // (docs/02-domain.md §5).
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@example.test']);
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'someone@external.example']);

        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/routing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Mailboxes/Routing')
                ->where('mailbox.username', 'joao@example.test')
                ->has('aliases', 1)
                ->where('aliases.0.address', 'sales@example.test')
                ->where('aliases.0.active', true)
                ->has('forwardings', 1)
                ->where('forwardings.0.forwarding', 'someone@external.example')
            );
    });

    it('lower cases both columns whatever was typed (AC-06)', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => '  Sales@Example.TEST '])
            ->assertRedirect();

        expect(routingRow('sales@example.test', 'joao@example.test'))->not->toBeNull();
    });

    it('never lets a submitted active reach the row (AC-27, AC-28)', function () {
        // The feature exposes create and delete only. `active` is written 1 and
        // is never accepted from a request (BR-19).
        $this->actingAs(globalAdmin())->post('/mailboxes/joao@example.test/forwardings', [
            'forwarding' => 'someone@external.example',
            'active' => 0,
        ])->assertRedirect();

        expect((int) routingRow('joao@example.test', 'someone@external.example')->active)->toBe(1);
    });
});

describe('the self-referencing row is untouchable (BR-06, AC-03, AC-04)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('is not in the forwardings listing', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'someone@external.example']);

        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/routing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Mailboxes/Routing')
                ->has('forwardings', 1)
                ->where('forwardings.0.forwarding', 'someone@external.example')
            );
    });

    it('refuses a delete aimed at its id, and leaves it exactly as it was', function () {
        $self = routingRow('joao@example.test', 'joao@example.test');

        $this->actingAs(globalAdmin())
            ->delete("/mailboxes/joao@example.test/forwardings/{$self->id}")
            ->assertSessionHasErrors('forwarding');

        $after = routingRow('joao@example.test', 'joao@example.test');

        expect($after)->not->toBeNull()
            ->and((int) $after->is_forwarding)->toBe(1)
            ->and((int) $after->active)->toBe(1);
    });
});

describe('the other two purposes of the table are invisible (BR-05, AC-05)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');

        foreach (['is_list', 'is_maillist'] as $flag) {
            DB::connection('vmail')->table('forwardings')->insert([
                'address' => 'joao@example.test',
                'forwarding' => $flag.'@example.test',
                'domain' => 'example.test',
                'dest_domain' => 'example.test',
                'is_forwarding' => 0,
                'is_alias' => 0,
                'is_list' => $flag === 'is_list' ? 1 : 0,
                'is_maillist' => $flag === 'is_maillist' ? 1 : 0,
                'active' => 1,
            ]);
        }
    });

    it('lists neither an is_list nor an is_maillist row', function () {
        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/routing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('aliases', 0)
                ->has('forwardings', 0)
            );
    });

    it('cannot delete one through either endpoint', function () {
        $row = routingRow('joao@example.test', 'is_list@example.test');

        $this->actingAs(globalAdmin())
            ->delete("/mailboxes/joao@example.test/forwardings/{$row->id}")
            ->assertNotFound();

        $this->actingAs(globalAdmin())
            ->delete("/mailboxes/joao@example.test/aliases/{$row->id}")
            ->assertNotFound();

        expect(routingRow('joao@example.test', 'is_list@example.test'))->not->toBeNull();
    });
});

describe('the duplicate pair is a validation error, not an exception (BR-07, AC-07)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('refuses the same alias pair submitted again in a different case', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@example.test']);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'SALES@EXAMPLE.TEST'])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('forwardings')
            ->where('forwarding', 'joao@example.test')->where('is_alias', 1)->count())->toBe(1);
    });

    it('refuses the same forwarding pair submitted again in a different case', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'someone@external.example']);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'Someone@External.Example'])
            ->assertSessionHasErrors('forwarding');
    });

    it('refuses a forwarding to the account itself, because that pair is the self-referencing row', function () {
        /*
         * AC-24 reads as though this should succeed. It cannot: the pair is
         * unique and BR-06's row already holds it. What AC-24 forbids — a graph
         * walk refusing loops — is not done, which the next test shows.
         */
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'joao@example.test'])
            ->assertSessionHasErrors('forwarding');

        expect(DB::connection('vmail')->table('forwardings')
            ->where('address', 'joao@example.test')->count())->toBe(1);
    });

    it('accepts two accounts forwarding to each other (AC-24)', function () {
        routingMailbox('maria@example.test');

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'maria@example.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/maria@example.test/forwardings', ['forwarding' => 'joao@example.test'])
            ->assertRedirect()->assertSessionHasNoErrors();
    });
});

describe('an alias address must be in a hosted domain (BR-16, AC-17, AC-18)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('refuses a domain this server does not host', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@nope.test'])
            ->assertSessionHasErrors('address');

        expect(DB::connection('vmail')->table('forwardings')->where('address', 'sales@nope.test')->count())->toBe(0);
    });

    it('refuses a domain that exists only as an alias domain', function () {
        // An alias domain has no accounts of its own (docs/02-domain.md §3).
        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'alias.test', 'target_domain' => 'example.test',
            'created' => now(), 'modified' => now(), 'active' => 1,
        ]);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@alias.test'])
            ->assertSessionHasErrors('address');
    });

    it('accepts one in a hosted domain', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@example.test'])
            ->assertRedirect()->assertSessionHasNoErrors();
    });
});

describe('a forwarding target (BR-18, AC-20 to AC-25)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('is refused when it is local and nothing of that name exists (AC-20)', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'ghost@example.test'])
            ->assertSessionHasErrors('forwarding');

        expect(routingRow('joao@example.test', 'ghost@example.test'))->toBeNull();
    });

    it('is accepted when it is external, and refused when it is not an address (AC-21)', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'anyone@external.example'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'not-an-address'])
            ->assertSessionHasErrors('forwarding');
    });

    it('is satisfied by a mailbox, a standalone alias or a per-account alias (AC-22)', function () {
        routingMailbox('bob@example.test');

        DB::connection('vmail')->table('alias')->insert([
            'address' => 'team@example.test', 'name' => 'Team', 'domain' => 'example.test',
            'created' => now(), 'modified' => now(), 'expired' => NeverExpiresDate::SENTINEL, 'active' => 1,
        ]);

        // A per-account alias of another account: `address` holds the alias
        // (OQ-A1, as read off BR-18 and AC-22).
        DB::connection('vmail')->table('forwardings')->insert([
            'address' => 'sales@example.test', 'forwarding' => 'bob@example.test',
            'domain' => 'example.test', 'dest_domain' => 'example.test',
            'is_forwarding' => 0, 'is_alias' => 1, 'is_list' => 0, 'is_maillist' => 0, 'active' => 1,
        ]);

        foreach (['bob@example.test', 'team@example.test', 'sales@example.test'] as $target) {
            $this->actingAs(globalAdmin())
                ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => $target])
                ->assertRedirect()->assertSessionHasNoErrors();

            expect(routingRow('joao@example.test', $target))->not->toBeNull();
        }
    });

    it('treats a domain that is only an alias domain as external (AC-23)', function () {
        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'list.test', 'target_domain' => 'example.test',
            'created' => now(), 'modified' => now(), 'active' => 1,
        ]);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'sales@list.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(routingRow('joao@example.test', 'sales@list.test'))->not->toBeNull();
    });

    it('checks existence and not liveness (AC-25)', function () {
        routingMailbox('dormant@example.test');
        Mailbox::withoutDomainScope()->whereKey('dormant@example.test')->update(['active' => 0]);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'dormant@example.test'])
            ->assertRedirect()->assertSessionHasNoErrors();
    });
});

describe('nothing is counted against a limit (BR-15, AC-16)', function () {
    it('creates ten per-account aliases in a domain whose alias limit is one', function () {
        // `domain.aliases` bounds standalone alias accounts only. The absence
        // of a limit check here is a decision, not an omission.
        makeDomain('example.test', ['aliases' => 1]);
        routingMailbox('joao@example.test');

        DB::connection('vmail')->table('alias')->insert([
            'address' => 'existing@example.test', 'name' => 'Existing', 'domain' => 'example.test',
            'created' => now(), 'modified' => now(), 'expired' => NeverExpiresDate::SENTINEL, 'active' => 1,
        ]);

        foreach (range(1, 10) as $n) {
            $this->actingAs(globalAdmin())
                ->post('/mailboxes/joao@example.test/aliases', ['address' => "alias{$n}@example.test"])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        expect(DB::connection('vmail')->table('forwardings')
            ->where('forwarding', 'joao@example.test')->where('is_alias', 1)->count())->toBe(10);
    });
});

describe('deletion touches one row (AC-12)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');
    });

    it('removes exactly the alias asked for', function () {
        foreach (['one@example.test', 'two@example.test'] as $alias) {
            $this->actingAs(globalAdmin())
                ->post('/mailboxes/joao@example.test/aliases', ['address' => $alias]);
        }

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/forwardings', ['forwarding' => 'away@external.example']);

        $row = routingRow('one@example.test', 'joao@example.test');

        $this->actingAs(globalAdmin())
            ->delete("/mailboxes/joao@example.test/aliases/{$row->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(routingRow('one@example.test', 'joao@example.test'))->toBeNull()
            ->and(routingRow('two@example.test', 'joao@example.test'))->not->toBeNull()
            ->and(routingRow('joao@example.test', 'away@external.example'))->not->toBeNull()
            // The invariant row survives every deletion this feature performs.
            ->and(routingRow('joao@example.test', 'joao@example.test'))->not->toBeNull();
    });

    it('refuses an id belonging to another account (AC-09)', function () {
        routingMailbox('maria@example.test');

        $this->actingAs(globalAdmin())
            ->post('/mailboxes/maria@example.test/aliases', ['address' => 'hers@example.test']);

        $row = routingRow('hers@example.test', 'maria@example.test');

        $this->actingAs(globalAdmin())
            ->delete("/mailboxes/joao@example.test/aliases/{$row->id}")
            ->assertNotFound();

        expect(routingRow('hers@example.test', 'maria@example.test'))->not->toBeNull();
    });
});

describe('scope (BR-02, AC-08, AC-26)', function () {
    beforeEach(function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');
        routingMailbox('a@mine.test');
        routingMailbox('b@theirs.test');
    });

    it('hides an account in a domain the actor does not administer', function () {
        // The scope lives in the query, so the account is not found rather than
        // forbidden (docs/policies/authorization.md §3). AC-08 names a 403;
        // every other screen in Mailward answers 404 here.
        $admin = domainAdmin('mine.test');

        $this->actingAs($admin)->get('/mailboxes/b@theirs.test/routing')->assertNotFound();
        $this->actingAs($admin)
            ->post('/mailboxes/b@theirs.test/aliases', ['address' => 'x@theirs.test'])
            ->assertNotFound();
    });

    it('lets a domain admin forward one of their accounts to a domain they do not administer', function () {
        // The destination plays no part in the authorization decision (AC-26).
        $this->actingAs(domainAdmin('mine.test'))
            ->post('/mailboxes/a@mine.test/forwardings', ['forwarding' => 'b@theirs.test'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(routingRow('a@mine.test', 'b@theirs.test'))->not->toBeNull();
    });
});

describe('every write is audited (BR-11, AC-13)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');

        // The audit log is Mailward's own database and survives the suite; the
        // counts below are about this test's writes only.
        AuditEntry::query()->delete();
    });

    it('records the create and the delete', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes/joao@example.test/aliases', ['address' => 'sales@example.test']);

        expect(AuditEntry::query()->where('event', 'created')->count())->toBe(1);

        $row = routingRow('sales@example.test', 'joao@example.test');

        $this->actingAs(globalAdmin())->delete("/mailboxes/joao@example.test/aliases/{$row->id}");

        $entry = AuditEntry::query()->where('event', 'deleted')->latest('id')->first();

        expect($entry)->not->toBeNull()
            ->and(json_encode($entry->properties))->toContain('sales@example.test');
    });
});

describe('a row written outside Mailward (BR-19, AC-29)', function () {
    it('is listed like any other, with no control offering to enable it', function () {
        makeDomain('example.test');
        routingMailbox('joao@example.test');

        DB::connection('vmail')->table('forwardings')->insert([
            'address' => 'joao@example.test', 'forwarding' => 'old@external.example',
            'domain' => 'example.test', 'dest_domain' => 'external.example',
            'is_forwarding' => 1, 'is_alias' => 0, 'is_list' => 0, 'is_maillist' => 0,
            'active' => 0,
        ]);

        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/routing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('forwardings', 1)
                ->where('forwardings.0.forwarding', 'old@external.example')
                ->where('forwardings.0.active', false)
            );

        expect((int) routingRow('joao@example.test', 'old@external.example')->active)->toBe(0);
    });
});
