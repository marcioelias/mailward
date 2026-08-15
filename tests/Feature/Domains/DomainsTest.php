<?php

declare(strict_types=1);

use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/domains.md. The authorisation rules are BR-19 to BR-21, and
 * the cascade is BR-16.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain', 'deleted_mailboxes'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

function globalAdmin(): Mailbox
{
    return new Mailbox(['username' => 'root@example.test', 'isglobaladmin' => true, 'isadmin' => true, 'active' => true]);
}

function domainAdmin(string ...$domains): Mailbox
{
    foreach ($domains as $domain) {
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'da@example.test',
            'domain' => $domain,
            'created' => now(),
            'modified' => now(),
            'expired' => '9999-12-31 00:00:00',
            'active' => 1,
        ]);
    }

    return new Mailbox(['username' => 'da@example.test', 'isadmin' => true, 'isglobaladmin' => false, 'active' => true]);
}

function makeDomain(string $name, array $attributes = []): Domain
{
    return Domain::withoutDomainScope()->create([
        'domain' => $name,
        'active' => true,
        'created' => now(),
        'modified' => now(),
        'expired' => '9999-12-31 00:00:00',
        ...$attributes,
    ]);
}

describe('listing', function () {
    it('shows every domain to a global admin', function () {
        makeDomain('one.test');
        makeDomain('two.test');

        $this->actingAs(globalAdmin())->get('/domains')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Domains/Index')
                ->has('domains.data', 2)
                ->where('can.create', true)
        );
    });

    it('shows a domain admin only their own, filtered in the query', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');

        $this->actingAs(domainAdmin('mine.test'))->get('/domains')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('domains.data', 1)
                ->where('domains.data.0.domain', 'mine.test')
                ->where('can.create', false)
        );
    });

    it('reports usage against the limit, where zero means unlimited', function () {
        makeDomain('one.test', ['mailboxes' => 0, 'aliases' => 5]);

        $this->actingAs(globalAdmin())->get('/domains')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('domains.data.0.limits.mailboxes', 0)
                ->where('domains.data.0.counts.mailboxes', 0)
        );
    });
});

describe('writes are global-admin only (BR-19)', function () {
    it('lets a global admin create a domain', function () {
        $this->actingAs(globalAdmin())->post('/domains', [
            'domain' => 'new.test',
            'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ])->assertRedirect('/domains');

        expect(Domain::withoutDomainScope()->find('new.test'))->not->toBeNull();
    });

    it('refuses a domain admin trying to create one', function () {
        $this->actingAs(domainAdmin('mine.test'))->post('/domains', [
            'domain' => 'new.test',
            'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ])->assertForbidden();

        expect(Domain::withoutDomainScope()->find('new.test'))->toBeNull();
    });

    it('refuses a domain admin editing a domain they administer', function () {
        makeDomain('mine.test', ['description' => 'original']);

        $this->actingAs(domainAdmin('mine.test'))
            ->put('/domains/mine.test', [
                'description' => 'changed',
                'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
            ])->assertForbidden();

        expect(Domain::withoutDomainScope()->find('mine.test')->description)->toBe('original');
    });

    it('refuses a domain admin deleting one', function () {
        makeDomain('mine.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->delete('/domains/mine.test')->assertForbidden();

        expect(Domain::withoutDomainScope()->find('mine.test'))->not->toBeNull();
    });
});

describe('creation writes the columns explicitly (BR-08, BR-09)', function () {
    it('never lets the driver default fire for the date columns', function () {
        $this->actingAs(globalAdmin())->post('/domains', [
            'domain' => 'new.test',
            'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ]);

        $raw = DB::connection('vmail')->table('domain')->where('domain', 'new.test')->first();

        // The "never expires" sentinel differs per driver, so the cast reads
        // either as null rather than comparing to a constant.
        expect(Domain::withoutDomainScope()->find('new.test')->expired)->toBeNull()
            ->and($raw->created)->not->toBeNull();
    });

    it('normalises a mixed-case domain name to lower case', function () {
        $this->actingAs(globalAdmin())->post('/domains', [
            'domain' => '  Example.TEST ',
            'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ])->assertRedirect();

        expect(Domain::withoutDomainScope()->find('example.test'))->not->toBeNull();
    });

    it('rejects a duplicate', function () {
        makeDomain('taken.test');

        $this->actingAs(globalAdmin())->post('/domains', [
            'domain' => 'taken.test',
            'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ])->assertSessionHasErrors('domain');
    });

    it('rejects a limit above the 32-bit maximum MySQL can hold', function () {
        $this->actingAs(globalAdmin())->post('/domains', [
            'domain' => 'new.test',
            'aliases' => 2147483648, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        ])->assertSessionHasErrors('aliases');
    });
});

describe('disabling (BR-21)', function () {
    it('writes active and touches nothing else', function () {
        makeDomain('one.test');
        DB::connection('vmail')->table('mailbox')->insert([
            'username' => 'user@one.test', 'domain' => 'one.test', 'password' => '{PLAIN}x',
            'active' => 1, 'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00',
        ]);

        $this->actingAs(globalAdmin())->post('/domains/one.test/active', ['active' => false]);

        expect(Domain::withoutDomainScope()->find('one.test')->active)->toBeFalse()
            // The accounts inside are deliberately untouched, which is what
            // makes the operation reversible.
            ->and(DB::connection('vmail')->table('mailbox')->where('username', 'user@one.test')->value('active'))
            ->toEqual(1);
    });

    it('round trips back to enabled', function () {
        makeDomain('one.test', ['active' => false]);

        $this->actingAs(globalAdmin())->post('/domains/one.test/active', ['active' => true]);

        expect(Domain::withoutDomainScope()->find('one.test')->active)->toBeTrue();
    });
});

describe('deletion cascades (BR-16)', function () {
    beforeEach(function () {
        makeDomain('doomed.test');

        DB::connection('vmail')->table('mailbox')->insert([
            'username' => 'user@doomed.test', 'domain' => 'doomed.test', 'password' => '{PLAIN}x',
            'storagebasedirectory' => '/var/vmail', 'storagenode' => 'vmail1',
            'maildir' => 'doomed.test/u/s/e/user/', 'active' => 1,
            'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00',
        ]);
        DB::connection('vmail')->table('forwardings')->insert([
            'address' => 'user@doomed.test', 'forwarding' => 'user@doomed.test',
            'domain' => 'doomed.test', 'dest_domain' => 'doomed.test',
            'is_forwarding' => 1, 'active' => 1,
        ]);
        DB::connection('vmail')->table('alias')->insert([
            'address' => 'sales@doomed.test', 'domain' => 'doomed.test',
            'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
        ]);
        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'other.test', 'target_domain' => 'doomed.test',
            'created' => now(), 'modified' => now(), 'active' => 1,
        ]);
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'someone@example.test', 'domain' => 'doomed.test',
            'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
        ]);
    });

    it('removes every dependant, leaving no orphan Postfix would still act on', function () {
        $this->actingAs(globalAdmin())->delete('/domains/doomed.test')->assertRedirect('/domains');

        $vmail = DB::connection('vmail');

        expect($vmail->table('domain')->where('domain', 'doomed.test')->count())->toBe(0)
            ->and($vmail->table('mailbox')->where('domain', 'doomed.test')->count())->toBe(0)
            ->and($vmail->table('forwardings')->where('domain', 'doomed.test')->count())->toBe(0)
            ->and($vmail->table('alias')->where('domain', 'doomed.test')->count())->toBe(0)
            ->and($vmail->table('alias_domain')->where('target_domain', 'doomed.test')->count())->toBe(0)
            ->and($vmail->table('domain_admins')->where('domain', 'doomed.test')->count())->toBe(0);
    });

    it('records the mail storage for iRedMail cron to remove, with an absolute path', function () {
        $this->actingAs(globalAdmin())->delete('/domains/doomed.test');

        $deleted = DB::connection('vmail')->table('deleted_mailboxes')
            ->where('username', 'user@doomed.test')->first();

        // The relative tail alone would leave the cron resolving a path that
        // does not exist, so the files would outlive the account in silence.
        expect($deleted)->not->toBeNull()
            ->and($deleted->maildir)->toBe('/var/vmail/vmail1/doomed.test/u/s/e/user')
            ->and($deleted->admin)->toBe('root@example.test');
    });
});

describe('a grant only counts while it is live (Q1)', function () {
    function grant(string $domain, array $overrides = []): void
    {
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'da@example.test',
            'domain' => $domain,
            'created' => now(),
            'modified' => now(),
            'expired' => '9999-12-31 00:00:00',
            'active' => 1,
            ...$overrides,
        ]);
    }

    function actorWithGrants(): Mailbox
    {
        return new Mailbox(['username' => 'da@example.test', 'isadmin' => true, 'isglobaladmin' => false, 'active' => true]);
    }

    it('ignores a grant that has been switched off', function () {
        makeDomain('suspended.test');
        grant('suspended.test', ['active' => 0]);

        $this->actingAs(actorWithGrants())->get('/domains')->assertInertia(
            fn (AssertableInertia $page) => $page->has('domains.data', 0)
        );
    });

    it('ignores a grant whose expiry has passed', function () {
        makeDomain('lapsed.test');
        grant('lapsed.test', ['expired' => now()->subDay()]);

        $this->actingAs(actorWithGrants())->get('/domains')->assertInertia(
            fn (AssertableInertia $page) => $page->has('domains.data', 0)
        );
    });

    it('keeps the live grants of an administrator whose other grant lapsed', function () {
        // The columns are on the grant, not on the person: one domain going
        // away must not take the others with it.
        makeDomain('live.test');
        makeDomain('lapsed.test');
        grant('live.test');
        grant('lapsed.test', ['active' => 0]);

        $this->actingAs(actorWithGrants())->get('/domains')->assertInertia(
            fn (AssertableInertia $page) => $page->has('domains.data', 1)
                ->where('domains.data.0.domain', 'live.test')
        );
    });

    it('still lets an administrator with no live grant sign in and see nothing', function () {
        // BR-A03: an empty screen, never an error. This is why the answer is
        // to ignore the grant rather than to deny the login.
        makeDomain('lapsed.test');
        grant('lapsed.test', ['active' => 0]);

        $this->actingAs(actorWithGrants())->get('/domains')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('domains.data', 0)
        );
    });
});
