<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/authentication.md BR-23 to BR-25. An administrator always
 * administers themselves, whatever the domain scope says.
 */
beforeEach(function () {
    foreach (['forwardings', 'mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

function signedInAccount(string $address, array $overrides = []): Mailbox
{
    $domain = str($address)->afterLast('@')->toString();

    Mailbox::withoutDomainScope()->create([
        'username' => $address,
        'domain' => $domain,
        'password' => app(SchemeRegistry::class)->hash('the current one'),
        'name' => 'Before',
        'isadmin' => true,
        'active' => true,
        'created' => now(),
        'modified' => now(),
        'expired' => '9999-12-31 00:00:00',
        ...$overrides,
    ]);

    return new Mailbox([
        'username' => $address,
        'isadmin' => true,
        'isglobaladmin' => $overrides['isglobaladmin'] ?? false,
        'active' => true,
    ]);
}

it('shows an administrator their own account even from outside their scope', function () {
    // The account lives in a domain this administrator does not administer.
    // Without the exemption they would be hidden from themselves and unable to
    // rotate the one password they must always be able to rotate.
    makeDomain('elsewhere.test');
    makeDomain('mine.test');

    $actor = signedInAccount('boss@elsewhere.test');
    DB::connection('vmail')->table('domain_admins')->insert([
        'username' => 'boss@elsewhere.test', 'domain' => 'mine.test',
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);

    $this->actingAs($actor)->get('/account')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Account/Edit')
            ->where('account.username', 'boss@elsewhere.test')
    );

    // And the exemption widens nothing: another account in that same domain
    // is still out of reach.
    signedInAccount('other@elsewhere.test');
    $this->actingAs($actor)->get('/mailboxes/other@elsewhere.test/edit')->assertNotFound();
});

it('updates the display name', function () {
    makeDomain('example.test');
    $actor = signedInAccount('admin@example.test');

    $this->actingAs($actor)->put('/account', ['name' => 'After'])->assertRedirect();

    expect(Mailbox::withoutDomainScope()->find('admin@example.test')->name)->toBe('After');
});

it('writes nothing but the name, whatever else is submitted', function () {
    // Quota, services, active and the administrator flags are decisions about
    // an account; making them about yourself is the self-escalation BR-A02
    // forbids.
    makeDomain('example.test');
    $actor = signedInAccount('admin@example.test', ['quota' => 100, 'isglobaladmin' => false]);

    $this->actingAs($actor)->put('/account', [
        'name' => 'After',
        'quota' => 999999,
        'isglobaladmin' => true,
        'active' => false,
    ])->assertRedirect();

    $mailbox = Mailbox::withoutDomainScope()->find('admin@example.test');

    expect($mailbox->name)->toBe('After')
        ->and($mailbox->quota)->toBe(100)
        ->and($mailbox->isglobaladmin)->toBeFalse()
        ->and($mailbox->active)->toBeTrue();
});

it('changes their own mail password and audits it under their own address', function () {
    makeDomain('example.test');
    $actor = signedInAccount('admin@example.test');

    $this->actingAs($actor)->put('/account/password', [
        'password' => 'a decent replacement',
        'password_confirmation' => 'a decent replacement',
    ])->assertRedirect();

    $stored = (string) Mailbox::withoutDomainScope()
        ->find('admin@example.test')->getAttribute('password');

    expect(app(SchemeRegistry::class)->verify('a decent replacement', $stored))->toBeTrue();

    $entry = AuditEntry::query()->where('event', 'password-changed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor)->toBe('admin@example.test')
        ->and(json_encode($entry->properties))->not->toContain('a decent replacement');
});

it('refuses a password below the minimum', function () {
    makeDomain('example.test');
    $actor = signedInAccount('admin@example.test');

    $this->actingAs($actor)->put('/account/password', [
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});
