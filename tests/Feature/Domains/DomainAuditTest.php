<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * docs/policies/authorization.md §7 — every write Mailward performs is
 * recorded with the acting address, the action, the target and the before and
 * after values.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain', 'deleted_mailboxes'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }

    AuditEntry::query()->delete();
});

it('records who created a domain, and from where', function () {
    $this->actingAs(globalAdmin())->post('/domains', [
        'domain' => 'new.test',
        'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
    ])->assertRedirect();

    $entry = AuditEntry::query()->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe('created')
        ->and($entry->actor)->toBe('root@example.test')
        ->and($entry->subject_id)->toBe('new.test')
        ->and($entry->ip_address)->not->toBeNull();
});

it('records what changed on an update, not the whole row', function () {
    makeDomain('one.test', ['description' => 'before']);

    $this->actingAs(globalAdmin())->put('/domains/one.test', [
        'description' => 'after',
        'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
    ])->assertRedirect();

    $entry = AuditEntry::query()->where('event', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['old']['description'] ?? null)->toBe('before')
        ->and($entry->properties['attributes']['description'] ?? null)->toBe('after');
});

it('records a deletion even though the subject no longer exists', function () {
    makeDomain('doomed.test', ['description' => 'gone soon']);

    $this->actingAs(globalAdmin())->delete('/domains/doomed.test')->assertRedirect();

    $entry = AuditEntry::query()->where('event', 'deleted')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['identifier'] ?? null)->toBe('doomed.test')
        ->and($entry->properties['old']['description'] ?? null)->toBe('gone soon')
        ->and($entry->actor)->toBe('root@example.test');
});

it('survives the account it refers to being deleted', function () {
    // The log is append-only and is the one table exempt from the orphan
    // cleanup a deletion performs (docs/02-domain.md §13).
    makeDomain('doomed.test');

    $this->actingAs(globalAdmin())->delete('/domains/doomed.test');

    expect(AuditEntry::query()->where('actor', 'root@example.test')->count())->toBeGreaterThan(0);
});

it('never records a password, wherever it appears in the payload', function () {
    // Stripped at the single point every write funnels through, rather than
    // trusted to be omitted by each caller (standards/security.md §10).
    Audit::record(
        'updated',
        makeDomain('one.test'),
        before: ['password' => 'the old one'],
        after: ['password' => 'the new one', 'description' => 'fine'],
    );

    $entry = AuditEntry::query()->latest('id')->first();

    expect($entry->properties['old']['password'])->toBe('[redacted]')
        ->and($entry->properties['attributes']['password'])->toBe('[redacted]')
        ->and($entry->properties['attributes']['description'])->toBe('fine');
});

it('attributes a write made outside a request to the console', function () {
    Audit::record('created', makeDomain('one.test'));

    expect(AuditEntry::query()->latest('id')->first()->actor)->toBe('console');
});

it('records the counts a cascade removed, in one entry (Q9)', function () {
    makeDomain('doomed.test');

    DB::connection('vmail')->table('mailbox')->insert([
        'username' => 'user@doomed.test', 'domain' => 'doomed.test', 'password' => '{PLAIN}x',
        'active' => 1, 'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00',
    ]);
    DB::connection('vmail')->table('alias')->insert([
        'address' => 'sales@doomed.test', 'domain' => 'doomed.test',
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);

    $this->actingAs(globalAdmin())->delete('/domains/doomed.test');

    $entries = AuditEntry::query()->where('event', 'deleted')->get();

    // One entry for a cascade of any size, naming what it removed.
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->properties['old']['removed']['mailboxes'])->toBe(1)
        ->and($entries->first()->properties['old']['removed']['aliases'])->toBe(1);
});

it('does not let a deleted account keep its grants over other domains (Q6)', function () {
    // Delete the domain, re-create an administrator at the same address, and
    // without this the new account silently regains every domain the old one
    // administered.
    makeDomain('doomed.test');
    makeDomain('elsewhere.test');

    DB::connection('vmail')->table('mailbox')->insert([
        'username' => 'admin@doomed.test', 'domain' => 'doomed.test', 'password' => '{PLAIN}x',
        'isadmin' => 1, 'active' => 1, 'created' => now(), 'modified' => now(),
        'expired' => '9999-12-31 00:00:00',
    ]);
    DB::connection('vmail')->table('domain_admins')->insert([
        'username' => 'admin@doomed.test', 'domain' => 'elsewhere.test',
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00', 'active' => 1,
    ]);

    $this->actingAs(globalAdmin())->delete('/domains/doomed.test');

    expect(DB::connection('vmail')->table('domain_admins')
        ->where('username', 'admin@doomed.test')->count())->toBe(0);
});

it('records the operating system user and host for a console write (Q10)', function () {
    // A console run has no IP. Recording the target's own address as the actor
    // would make the log claim an account promoted itself, so it records the
    // only identity the invocation actually has.
    Audit::record('created', makeDomain('one.test'));

    $entry = AuditEntry::query()->latest('id')->first();

    expect($entry->actor)->toBe(AuditEntry::CONSOLE_ACTOR)
        ->and($entry->ip_address)->toContain('@')
        ->and($entry->ip_address)->not->toBe('127.0.0.1');
});
