<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/mailboxes.md. The rule that matters most is BR-04: creating a
 * mailbox is never a single insert.
 */
beforeEach(function () {
    foreach (['forwardings', 'deleted_mailboxes', 'mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

function creationPayload(array $overrides = []): array
{
    return [
        'username' => 'joao@example.test',
        'password' => 'a decent password',
        'password_confirmation' => 'a decent password',
        'name' => 'João',
        'quota' => 1024,
        ...$overrides,
    ];
}

describe('creation writes both rows or neither (BR-04)', function () {
    it('writes the self-referencing forwarding alongside the mailbox', function () {
        // Without this row the account appears correct in every listing and
        // receives no mail (docs/02-domain.md §5).
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload())->assertRedirect();

        $forwarding = DB::connection('vmail')->table('forwardings')
            ->where('address', 'joao@example.test')->first();

        expect(Mailbox::withoutDomainScope()->find('joao@example.test'))->not->toBeNull()
            ->and($forwarding)->not->toBeNull()
            ->and($forwarding->forwarding)->toBe('joao@example.test')
            ->and((int) $forwarding->is_forwarding)->toBe(1);
    });

    it('writes no mailbox at all when the address is rejected', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => 'joao@nowhere.test']))
            ->assertSessionHasErrors('username');

        expect(DB::connection('vmail')->table('mailbox')->count())->toBe(0)
            ->and(DB::connection('vmail')->table('forwardings')->count())->toBe(0);
    });
});

describe('the address and its domain', function () {
    it('refuses a domain that does not exist (BR-26)', function () {
        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => 'joao@nowhere.test']))
            ->assertSessionHasErrors('username');
    });

    it('refuses a domain that exists only as an alias domain (BR-26)', function () {
        // An alias domain has no accounts of its own; a mailbox inside one
        // would be unreachable.
        makeDomain('real.test');
        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'aliased.test', 'target_domain' => 'real.test',
            'created' => now(), 'modified' => now(), 'active' => 1,
        ]);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => 'joao@aliased.test']))
            ->assertSessionHasErrors('username');
    });

    it('lower cases the address before writing it', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => '  Joao@Example.TEST ']))
            ->assertRedirect();

        expect(Mailbox::withoutDomainScope()->find('joao@example.test'))->not->toBeNull();
    });

    it('refuses a duplicate address', function () {
        makeDomain('example.test');
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload())
            ->assertSessionHasErrors('username');
    });
});

describe('storage columns', function () {
    it('writes the relative maildir and the two columns it hangs from', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $row = DB::connection('vmail')->table('mailbox')->where('username', 'joao@example.test')->first();

        expect($row->maildir)->toStartWith('example.test/j/o/a/joao-')
            // Not absolute: Dovecot concatenates the three.
            ->and($row->maildir)->not->toStartWith('/')
            ->and($row->storagebasedirectory)->toBe('/var/vmail')
            ->and($row->storagenode)->toBe('vmail1');
    });

    it('writes the date columns explicitly rather than letting the default fire', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $mailbox = Mailbox::withoutDomainScope()->find('joao@example.test');

        expect($mailbox->expired)->toBeNull()
            ->and($mailbox->created)->not->toBeNull()
            ->and($mailbox->passwordlastchange)->not->toBeNull()
            // NULL when unrestricted, never an empty string (BR-08).
            ->and($mailbox->allow_nets)->toBeNull();
    });
});

describe('the password is the real mail password', function () {
    it('writes it in the configured generative scheme', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $stored = (string) Mailbox::withoutDomainScope()
            ->find('joao@example.test')->getAttribute('password');

        expect($stored)->toStartWith('{SSHA512}')
            ->and(app(SchemeRegistry::class)->verify('a decent password', $stored))->toBeTrue();
    });

    it('never lets the password reach the audit log', function () {
        makeDomain('example.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $entry = AuditEntry::query()->latest('id')->first();

        expect(json_encode($entry->properties))->not->toContain('a decent password')
            ->and(json_encode($entry->properties))->not->toContain('{SSHA512}');
    });

    it('changes it and records that it changed, not what to', function () {
        makeDomain('example.test');
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $this->actingAs(globalAdmin())->put('/mailboxes/joao@example.test/password', [
            'password' => 'a different password',
            'password_confirmation' => 'a different password',
        ])->assertRedirect();

        $stored = (string) Mailbox::withoutDomainScope()
            ->find('joao@example.test')->getAttribute('password');

        expect(app(SchemeRegistry::class)->verify('a different password', $stored))->toBeTrue();

        $entry = AuditEntry::query()->where('event', 'password-changed')->latest('id')->first();

        expect($entry)->not->toBeNull()
            ->and(json_encode($entry->properties))->not->toContain('a different password');
    });
});

describe('the domain limits are guardrails (BR-05, BR-28, BR-29)', function () {
    it('refuses a create once the mailbox limit is reached', function () {
        makeDomain('example.test', ['mailboxes' => 1]);
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => 'maria@example.test']))
            ->assertSessionHasErrors('username');
    });

    it('treats a zero limit as unlimited, not as none allowed', function () {
        makeDomain('example.test', ['mailboxes' => 0]);

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload())->assertRedirect();

        expect(Mailbox::withoutDomainScope()->find('joao@example.test'))->not->toBeNull();
    });

    it('refuses rather than truncating when the quota pool is short', function () {
        // iRedMail silently reduces the quota to what is left and creates the
        // account anyway. Mailward refuses and names the balance (BR-28).
        makeDomain('example.test', ['maxquota' => 1000]);

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['quota' => 4000]))
            ->assertSessionHasErrors('quota');

        expect(DB::connection('vmail')->table('mailbox')->count())->toBe(0);
    });

    it('measures the pool against what the domain has already allocated', function () {
        makeDomain('example.test', ['maxquota' => 2000]);
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload(['quota' => 1500]));

        $this->actingAs(globalAdmin())
            ->post('/mailboxes', creationPayload(['username' => 'maria@example.test', 'quota' => 600]))
            ->assertSessionHasErrors('quota');
    });
});

describe('deletion (BR-16, BR-23, BR-24, BR-27)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());
    });

    it('records the storage for iRedMail cron, with an absolute path', function () {
        $this->actingAs(globalAdmin())->delete('/mailboxes/joao@example.test')->assertRedirect();

        $deleted = DB::connection('vmail')->table('deleted_mailboxes')
            ->where('username', 'joao@example.test')->first();

        expect($deleted)->not->toBeNull()
            ->and($deleted->maildir)->toStartWith('/var/vmail/vmail1/example.test/j/o/a/joao-');
    });

    it('removes every forwarding naming the address, in either direction', function () {
        DB::connection('vmail')->table('forwardings')->insert([
            'address' => 'other@example.test', 'forwarding' => 'joao@example.test',
            'domain' => 'example.test', 'dest_domain' => 'example.test',
            'is_forwarding' => 1, 'active' => 1,
        ]);

        $this->actingAs(globalAdmin())->delete('/mailboxes/joao@example.test');

        expect(DB::connection('vmail')->table('forwardings')
            ->where('address', 'joao@example.test')->orWhere('forwarding', 'joao@example.test')
            ->count())->toBe(0);
    });

    it('removes the grants the account held over other domains', function () {
        makeDomain('elsewhere.test');
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'joao@example.test', 'domain' => 'elsewhere.test',
            'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
        ]);

        $this->actingAs(globalAdmin())->delete('/mailboxes/joao@example.test');

        expect(DB::connection('vmail')->table('domain_admins')
            ->where('username', 'joao@example.test')->count())->toBe(0);
    });

    it('refuses an administrator deleting their own account', function () {
        // BR-A02: nobody removes themselves from the panel.
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload([
            'username' => 'root@example.test',
        ]));

        $this->actingAs(globalAdmin())->delete('/mailboxes/root@example.test')->assertForbidden();
    });
});

describe('scope', function () {
    it('shows a domain admin only the mailboxes of their domains', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');

        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload(['username' => 'a@mine.test']));
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload(['username' => 'b@theirs.test']));

        $this->actingAs(domainAdmin('mine.test'))->get('/mailboxes')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Mailboxes/Index')
                ->has('mailboxes.data', 1)
                ->where('mailboxes.data.0.username', 'a@mine.test')
        );
    });
});

describe('the password screen (BR-31 to BR-36)', function () {
    beforeEach(function () {
        makeDomain('example.test');
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload());
    });

    it('has a screen of its own, and sends no password value to it', function () {
        // The suggestion is generated in the browser precisely so that no
        // password nobody chose reaches the page payload, the browser history
        // or the devtools.
        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/password')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Mailboxes/Password')
                ->where('mailbox.username', 'joao@example.test')
                ->missing('mailbox.password')
                ->missing('suggestion')
            );
    });

    it('is refused for an account outside the actor\'s scope', function () {
        makeDomain('theirs.test');
        $this->actingAs(globalAdmin())->post('/mailboxes', creationPayload([
            'username' => 'someone@theirs.test',
        ]));

        $this->actingAs(domainAdmin('example.test'))
            ->get('/mailboxes/someone@theirs.test/password')
            ->assertNotFound();
    });

    it('refuses a password below the minimum length', function () {
        $before = (string) Mailbox::withoutDomainScope()
            ->find('joao@example.test')->getAttribute('password');

        $this->actingAs(globalAdmin())->put('/mailboxes/joao@example.test/password', [
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        expect((string) Mailbox::withoutDomainScope()
            ->find('joao@example.test')->getAttribute('password'))->toBe($before);
    });

    it('refuses when the confirmation differs', function () {
        $this->actingAs(globalAdmin())->put('/mailboxes/joao@example.test/password', [
            'password' => 'a perfectly fine one', 'password_confirmation' => 'a different one',
        ])->assertSessionHasErrors('password');
    });

    it('reports a password weaker than the one it would write', function () {
        Mailbox::withoutDomainScope()->where('username', 'joao@example.test')
            ->update(['password' => '{CRYPT}'.password_hash('x', PASSWORD_BCRYPT, ['cost' => 5])]);

        $this->actingAs(globalAdmin())->get('/mailboxes/joao@example.test/password')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mailbox.weakness', fn (?string $weakness) => str_contains((string) $weakness, 'cost 5'))
            );
    });
});
