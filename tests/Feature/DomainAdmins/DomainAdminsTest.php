<?php

declare(strict_types=1);

use App\Actions\DomainAdmins\DemoteAdministratorAction;
use App\Exceptions\LastGlobalAdmin;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/domain-admins.md.
 *
 * Every write here is global-admin only (BR-15). The rules that keep the
 * organisation out of a locked panel are BR-A01 and BR-A02
 * (docs/policies/authorization.md §5) and they are the sharp edge of this
 * feature.
 */
beforeEach(function () {
    foreach (['mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

/**
 * An actor, built in memory exactly as the rest of this suite builds them.
 * BR-03 makes `mailbox.isglobaladmin` the authoritative count for BR-A01, so
 * an actor that is not itself a row is what lets the last-global-admin case be
 * reached from the outside.
 */
function daActor(): Mailbox
{
    return new Mailbox([
        'username' => 'actor@example.test',
        'isadmin' => true,
        'isglobaladmin' => true,
        'active' => true,
    ]);
}

function daDomainActor(string ...$domains): Mailbox
{
    foreach ($domains as $domain) {
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'da-actor@example.test',
            'domain' => $domain,
            'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
        ]);
    }

    return new Mailbox([
        'username' => 'da-actor@example.test',
        'isadmin' => true,
        'isglobaladmin' => false,
        'active' => true,
    ]);
}

function daDomain(string $name): void
{
    DB::connection('vmail')->table('domain')->insertOrIgnore([
        'domain' => $name,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);
}

/** A persisted mail account. BR-A01 counts rows, so the actor must be real. */
function daAccount(string $address, array $attributes = []): Mailbox
{
    $domain = substr($address, (int) strpos($address, '@') + 1);

    DB::connection('vmail')->table('domain')->insertOrIgnore([
        'domain' => $domain,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);

    DB::connection('vmail')->table('mailbox')->insert([
        'username' => $address,
        'domain' => $domain,
        'password' => '{PLAIN}x',
        'name' => '',
        'isadmin' => 0,
        'isglobaladmin' => 0,
        'active' => 1,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00',
        ...$attributes,
    ]);

    return Mailbox::withoutDomainScope()->findOrFail($address);
}

/** A persisted global admin, in both of its representations (BR-04). */
function daGlobalAdmin(string $address): Mailbox
{
    $mailbox = daAccount($address, ['isadmin' => 1, 'isglobaladmin' => 1]);

    DB::connection('vmail')->table('domain_admins')->insert([
        'username' => $address,
        'domain' => DomainAdmin::ALL_DOMAINS,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);

    return $mailbox;
}

function daGrant(string $address, string $domain): void
{
    DB::connection('vmail')->table('domain_admins')->insert([
        'username' => $address,
        'domain' => $domain,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);
}

function daFlag(string $address, string $column): int
{
    return (int) DB::connection('vmail')->table('mailbox')
        ->where('username', $address)->value($column);
}

function daGrantRows(string $address, ?string $domain = null): int
{
    return DB::connection('vmail')->table('domain_admins')
        ->where('username', $address)
        ->when($domain !== null, fn ($query) => $query->where('domain', $domain))
        ->count();
}

describe('listing (AC-01, AC-02)', function () {
    it('lists every account carrying either flag, to a global admin', function () {
        daAccount('plain@example.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        daGlobalAdmin('root@example.test');

        $this->actingAs(daActor())->get('/admins')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('DomainAdmins/Index')
                ->has('administrators.data', 2)
        );
    });

    it("resolves a global admin's domains from the flag, not from the ALL row (BR-05)", function () {
        // The sentinel names no row in `domain`. A query joining the two would
        // return zero rows for exactly the administrator who sees everything.
        daDomain('two.test');
        daDomain('three.test');
        daGlobalAdmin('root@one.test');

        $this->actingAs(daActor())->get('/admins')->assertInertia(
            fn (AssertableInertia $page) => $page->has('administrators.data.0.domains', 3)
        );
    });

    it('never shows the ALL sentinel as if it were a domain (BR-05)', function () {
        daGlobalAdmin('root@one.test');

        $this->actingAs(daActor())->get('/admins')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('administrators.data.0.domains', ['one.test'])
        );
    });

    it('shows a domain admin only the grants covering their own domains', function () {
        daDomain('mine.test');
        daDomain('theirs.test');
        daAccount('peer@example.test', ['isadmin' => 1]);
        daAccount('stranger@example.test', ['isadmin' => 1]);
        daGrant('peer@example.test', 'mine.test');
        daGrant('stranger@example.test', 'theirs.test');

        $this->actingAs(daDomainActor('mine.test'))->get('/admins')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('administrators.data', 1)
                ->where('administrators.data.0.address', 'peer@example.test')
                ->where('can.create', false)
        );
    });

    it('renders the empty state for an administrator whose domains are gone (BR-A03)', function () {
        // Reached only by a domain being deleted. It is a state, not an error.
        daAccount('orphan@example.test', ['isadmin' => 1]);

        $this->actingAs(daActor())->get('/admins')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('administrators.data.0.domains', [])
        );
    });
});

describe('granting the global flag (AC-04, AC-05)', function () {
    it('writes both representations in one commit (BR-04)', function () {
        daAccount('user@example.test');

        $this->actingAs(daActor())
            ->post('/admins/user@example.test/global')->assertRedirect();

        expect(daFlag('user@example.test', 'isadmin'))->toBe(1)
            ->and(daFlag('user@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('user@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('is idempotent on an account that is already global (AC-05)', function () {
        daGlobalAdmin('user@example.test');

        $this->actingAs(daActor())
            ->post('/admins/user@example.test/global')->assertRedirect();

        expect(daGrantRows('user@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('repairs a drifted pair through an explicit, audited write (BR-19, AC-28)', function () {
        daAccount('drifted@example.test', ['isadmin' => 1]);
        daGrant('drifted@example.test', DomainAdmin::ALL_DOMAINS);

        // The flag is authoritative (BR-03), so the ALL row alone made this
        // account nothing. Mailward never repairs drift during a read.
        $this->actingAs(daActor())->get('/admins')->assertOk();

        expect(daFlag('drifted@example.test', 'isglobaladmin'))->toBe(0);

        $this->actingAs(daActor())->post('/admins/drifted@example.test/global');

        expect(daFlag('drifted@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('drifted@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });
});

describe('revoking the global flag (AC-06)', function () {
    it('clears the flag and removes the ALL row', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)
            ->delete('/admins/other@example.test/global')->assertRedirect();

        expect(daFlag('other@example.test', 'isglobaladmin'))->toBe(0)
            ->and(daGrantRows('other@example.test', DomainAdmin::ALL_DOMAINS))->toBe(0);
    });

    it('leaves the account signed in as a plain administrator', function () {
        // Revoking global is not a demotion: isadmin survives (BR-14, BR-16).
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)->delete('/admins/other@example.test/global');

        expect(daFlag('other@example.test', 'isadmin'))->toBe(1);
    });
});

describe('BR-A01 — the last global admin cannot be demoted (AC-07)', function () {
    it('refuses to revoke the global flag of the only global admin, and rolls back', function () {
        /*
         * The actor is not a persisted row, exactly as the rest of this suite
         * builds actors. BR-03 makes `mailbox.isglobaladmin` the authoritative
         * count, so the only global admin in the database is the target.
         */
        daGlobalAdmin('only@example.test');

        $this->actingAs(daActor())
            ->delete('/admins/only@example.test/global')
            ->assertSessionHasErrors('global');

        expect(daFlag('only@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('only@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('refuses a full demotion of the only global admin, and rolls back', function () {
        daGlobalAdmin('only@example.test');
        daDomain('one.test');
        daGrant('only@example.test', 'one.test');

        $this->actingAs(daActor())
            ->delete('/admins/only@example.test')
            ->assertSessionHasErrors('global');

        expect(daFlag('only@example.test', 'isadmin'))->toBe(1)
            ->and(daFlag('only@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('only@example.test'))->toBe(2);
    });

    it('raises inside the transaction, so nothing is written', function () {
        // The guard is in the action rather than in the policy: it is a domain
        // invariant about the resulting state, and the check and the rollback
        // have to be the same event.
        $only = daGlobalAdmin('only@example.test');

        expect(fn () => app(DemoteAdministratorAction::class)->revokeGlobal($only))
            ->toThrow(LastGlobalAdmin::class);

        expect(daFlag('only@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('only@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('permits the demotion once a second global admin exists', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)
            ->delete('/admins/other@example.test/global')->assertSessionHasNoErrors();

        expect(daFlag('other@example.test', 'isglobaladmin'))->toBe(0);
    });

    it('does not block demoting an account that was never a global admin', function () {
        daDomain('one.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        daGrant('da@example.test', 'one.test');

        $this->actingAs(daActor())->delete('/admins/da@example.test')->assertRedirect();

        expect(daFlag('da@example.test', 'isadmin'))->toBe(0);
    });
});

describe('BR-A02 — nobody revokes their own global flag (AC-08)', function () {
    it('refuses self-revocation even though another global admin exists', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)
            ->delete('/admins/root@example.test/global')->assertForbidden();

        expect(daFlag('root@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('root@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('refuses a full self-demotion, which would revoke the same flag', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)->delete('/admins/root@example.test')->assertForbidden();

        expect(daFlag('root@example.test', 'isadmin'))->toBe(1)
            ->and(daFlag('root@example.test', 'isglobaladmin'))->toBe(1);
    });

    it('still lets them revoke somebody else', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)
            ->delete('/admins/other@example.test/global')->assertRedirect();

        expect(daFlag('other@example.test', 'isglobaladmin'))->toBe(0);
    });
});

describe('assigning domains (AC-09 to AC-12)', function () {
    it('writes one row with active = 1 and the values Mailward chose (AC-09)', function () {
        daDomain('one.test');
        daAccount('da@example.test');

        $this->actingAs(daActor())
            ->post('/admins/da@example.test/domains', ['domain' => 'one.test'])
            ->assertRedirect();

        $row = DB::connection('vmail')->table('domain_admins')
            ->where('username', 'da@example.test')->first();

        expect((int) $row->active)->toBe(1)
            ->and($row->created)->not->toBeNull()
            ->and($row->modified)->not->toBeNull()
            ->and((string) $row->expired)->toStartWith('9999-12-31')

            // A grant confers nothing without the panel flag (BR-16's mirror).
            ->and(daFlag('da@example.test', 'isadmin'))->toBe(1);
    });

    it('is idempotent when the same assignment is repeated (AC-10, BR-02)', function () {
        daDomain('one.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        daGrant('da@example.test', 'one.test');

        $this->actingAs(daActor())
            ->post('/admins/da@example.test/domains', ['domain' => 'one.test'])
            ->assertRedirect();

        expect(daGrantRows('da@example.test', 'one.test'))->toBe(1);
    });

    it('revives a grant that was inactive or expired', function () {
        // An inactive or expired row confers nothing, so leaving it in place
        // would make the assignment silently fail (BR-10).
        daDomain('one.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'da@example.test', 'domain' => 'one.test',
            'created' => now(), 'modified' => now(), 'expired' => '2000-01-01 00:00:00', 'active' => 0,
        ]);

        $this->actingAs(daActor())
            ->post('/admins/da@example.test/domains', ['domain' => 'one.test']);

        $row = DB::connection('vmail')->table('domain_admins')
            ->where('username', 'da@example.test')->first();

        expect((int) $row->active)->toBe(1)
            ->and((string) $row->expired)->toStartWith('9999-12-31');
    });

    it('removes one grant and never the ALL row (AC-11)', function () {
        daDomain('one.test');
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('both@example.test');
        daGrant('both@example.test', 'one.test');

        $this->actingAs($actor)
            ->delete('/admins/both@example.test/domains/one.test')->assertRedirect();

        expect(daGrantRows('both@example.test', 'one.test'))->toBe(0)
            ->and(daGrantRows('both@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1);
    });

    it('lower-cases both sides at the boundary (AC-12, BR-08)', function () {
        daDomain('example.com');
        daAccount('admin@example.com');

        $this->actingAs(daActor())
            ->post('/admins/Admin@Example.COM/domains', ['domain' => 'Example.COM'])
            ->assertRedirect();

        expect(daGrantRows('admin@example.com', 'example.com'))->toBe(1);
    });

    it('rejects a non-ASCII domain before any write is attempted (AC-13, BR-07)', function () {
        // domain_admins is CHARACTER SET ascii on MySQL, so an IDN cannot be
        // stored there at all — it is refused rather than truncated.
        daAccount('da@example.test', ['isadmin' => 1]);

        $this->actingAs(daActor())
            ->post('/admins/da@example.test/domains', ['domain' => 'exâmple.test'])
            ->assertSessionHasErrors('domain');

        expect(daGrantRows('da@example.test'))->toBe(0);
    });

    it('rejects a domain that does not exist on this server', function () {
        daAccount('da@example.test', ['isadmin' => 1]);

        $this->actingAs(daActor())
            ->post('/admins/da@example.test/domains', ['domain' => 'nowhere.test'])
            ->assertSessionHasErrors('domain');

        expect(daGrantRows('da@example.test'))->toBe(0);
    });
});

describe('every write is global-admin only (BR-15)', function () {
    it('refuses a domain admin granting the global flag (AC-14, BR-11)', function () {
        daDomain('mine.test');
        daAccount('peer@mine.test', ['isadmin' => 1]);

        $this->actingAs(daDomainActor('mine.test'))
            ->post('/admins/peer@mine.test/global')->assertForbidden();

        expect(daFlag('peer@mine.test', 'isglobaladmin'))->toBe(0);
    });

    it('refuses a domain admin assigning a domain they administer (AC-20)', function () {
        daDomain('mine.test');
        daAccount('peer@mine.test', ['isadmin' => 1]);

        $this->actingAs(daDomainActor('mine.test'))
            ->post('/admins/peer@mine.test/domains', ['domain' => 'mine.test'])
            ->assertForbidden();

        expect(daGrantRows('peer@mine.test'))->toBe(0);
    });

    it('refuses a domain admin removing a grant on a domain they administer (AC-21)', function () {
        daDomain('mine.test');
        daAccount('peer@mine.test', ['isadmin' => 1]);
        daGrant('peer@mine.test', 'mine.test');

        $this->actingAs(daDomainActor('mine.test'))
            ->delete('/admins/peer@mine.test/domains/mine.test')->assertForbidden();

        expect(daGrantRows('peer@mine.test', 'mine.test'))->toBe(1);
    });

    it('refuses a domain admin promoting an account', function () {
        daDomain('mine.test');
        daAccount('peer@mine.test');

        $this->actingAs(daDomainActor('mine.test'))
            ->post('/admins', ['username' => 'peer@mine.test'])->assertForbidden();

        expect(daFlag('peer@mine.test', 'isadmin'))->toBe(0);
    });

    it('refuses a domain admin demoting one', function () {
        daDomain('mine.test');
        daAccount('peer@mine.test', ['isadmin' => 1]);

        $this->actingAs(daDomainActor('mine.test'))
            ->delete('/admins/peer@mine.test')->assertForbidden();

        expect(daFlag('peer@mine.test', 'isadmin'))->toBe(1);
    });
});

describe('promoting (BR-01)', function () {
    it('promotes an existing mailbox with its initial domains', function () {
        daDomain('one.test');
        daDomain('two.test');
        daAccount('user@example.test');

        $this->actingAs(daActor())->post('/admins', [
            'username' => 'user@example.test',
            'domains' => ['one.test', 'two.test'],
        ])->assertRedirect('/admins');

        expect(daFlag('user@example.test', 'isadmin'))->toBe(1)
            ->and(daGrantRows('user@example.test'))->toBe(2);
    });

    it('refuses to promote an address with no mailbox behind it', function () {
        $this->actingAs(daActor())->post('/admins', ['username' => 'nobody@example.test'])
            ->assertSessionHasErrors('username');
    });

    it('rejects a non-ASCII address (BR-07)', function () {
        $this->actingAs(daActor())->post('/admins', ['username' => 'usuário@example.test'])
            ->assertSessionHasErrors('username');

        expect(DB::connection('vmail')->table('domain_admins')->count())->toBe(0);
    });
});

describe('demotion is not a deletion (AC-19, AC-22, AC-23, BR-14, BR-16)', function () {
    it('clears isadmin and removes every grant (AC-22, BR-16)', function () {
        daDomain('one.test');
        daDomain('two.test');
        $actor = daGlobalAdmin('root@example.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        daGrant('da@example.test', 'one.test');
        daGrant('da@example.test', 'two.test');

        $this->actingAs($actor)->delete('/admins/da@example.test')->assertRedirect('/admins');

        expect(daFlag('da@example.test', 'isadmin'))->toBe(0)
            ->and(daGrantRows('da@example.test'))->toBe(0);
    });

    it('leaves the mail account itself intact, with active unchanged (BR-14)', function () {
        $actor = daGlobalAdmin('root@example.test');
        daAccount('da@example.test', ['isadmin' => 1]);

        $this->actingAs($actor)->delete('/admins/da@example.test');

        $row = DB::connection('vmail')->table('mailbox')
            ->where('username', 'da@example.test')->first();

        expect($row)->not->toBeNull()
            ->and((int) $row->active)->toBe(1)
            ->and($row->password)->toBe('{PLAIN}x');
    });

    it('can be promoted again afterwards (AC-23)', function () {
        daDomain('one.test');
        $actor = daGlobalAdmin('root@example.test');
        daAccount('da@example.test', ['isadmin' => 1]);
        daGrant('da@example.test', 'one.test');

        $this->actingAs($actor)->delete('/admins/da@example.test');
        $this->actingAs($actor)->post('/admins', [
            'username' => 'da@example.test', 'domains' => ['one.test'],
        ])->assertRedirect();

        expect(daFlag('da@example.test', 'isadmin'))->toBe(1)
            ->and(daGrantRows('da@example.test', 'one.test'))->toBe(1);
    });

    it('removes the ALL row too, landing in "not an administrator"', function () {
        $actor = daGlobalAdmin('root@example.test');
        daGlobalAdmin('other@example.test');

        $this->actingAs($actor)->delete('/admins/other@example.test')->assertRedirect();

        expect(daFlag('other@example.test', 'isadmin'))->toBe(0)
            ->and(daFlag('other@example.test', 'isglobaladmin'))->toBe(0)
            ->and(daGrantRows('other@example.test'))->toBe(0);
    });
});

describe('reads never write (AC-28)', function () {
    it('changes no vmail row while rendering the listing and the form', function () {
        daDomain('one.test');
        daAccount('drifted@example.test', ['isadmin' => 1, 'isglobaladmin' => 1]);
        daAccount('other@example.test', ['isadmin' => 1]);
        daGrant('other@example.test', DomainAdmin::ALL_DOMAINS);

        $this->actingAs(daActor())->get('/admins')->assertOk();
        $this->actingAs(daActor())->get('/admins/drifted@example.test/edit')->assertOk();

        // Both accounts are still in exactly the drifted state, and BR-03 is
        // what decides which of them is a global admin.
        expect(daGrantRows('drifted@example.test', DomainAdmin::ALL_DOMAINS))->toBe(0)
            ->and(daFlag('drifted@example.test', 'isglobaladmin'))->toBe(1)
            ->and(daGrantRows('other@example.test', DomainAdmin::ALL_DOMAINS))->toBe(1)
            ->and(daFlag('other@example.test', 'isglobaladmin'))->toBe(0);
    });
});
