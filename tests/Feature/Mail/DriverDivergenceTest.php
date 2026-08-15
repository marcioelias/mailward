<?php

declare(strict_types=1);

use App\Exceptions\ReadOnlyMailTable;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Models\Mail\UsedQuota;
use Illuminate\Support\Facades\DB;

/**
 * These run against the real DDL iRedMail ships, loaded into a local vmail_test
 * database on both drivers by ./scripts/dev-databases.sh.
 *
 * The point is not that the models work. It is that they write values the
 * *other* driver would have rejected — the failures this suite exists to catch
 * are invisible on one driver and fatal on the other
 * (docs/reference/schema-type-matrix.md).
 */
beforeEach(function () {
    /*
     * These exercise the driver layer, not authorisation, so they act as a
     * global admin: the domain scope denies everything without an actor, by
     * design (docs/policies/authorization.md §3).
     */
    $this->actingAs(new Mailbox(['username' => 'root@example.test', 'isglobaladmin' => true, 'active' => true]));

    DB::connection('vmail')->table('mailbox')->delete();
    DB::connection('vmail')->table('domain')->delete();
});

afterEach(function () {
    DB::connection('vmail')->table('mailbox')->delete();
    DB::connection('vmail')->table('domain')->delete();
});

it('writes flags as integers, not as booleans (D4)', function () {
    Domain::create(['domain' => 'example.test', 'active' => true, 'backupmx' => false]);

    // PDO's PostgreSQL driver binds a PHP bool as 't'/'f', which INT2 rejects.
    // Reaching this line at all is the assertion on that driver.
    $raw = DB::connection('vmail')->table('domain')
        ->where('domain', 'example.test')->first();

    expect((int) $raw->active)->toBe(1)
        ->and((int) $raw->backupmx)->toBe(0);

    $domain = Domain::query()->find('example.test');

    expect($domain->active)->toBeTrue()
        ->and($domain->backupmx)->toBeFalse();
});

it('reads the never-expires sentinel as null on either driver (D2)', function () {
    Domain::create(['domain' => 'example.test', 'active' => true, 'expired' => null]);

    $domain = Domain::query()->find('example.test');

    expect($domain->expired)->toBeNull();
});

it('writes the SOGo toggles as characters, not integers (D5)', function () {
    Domain::create(['domain' => 'example.test', 'active' => true]);

    Mailbox::create([
        'username' => 'someone@example.test',
        'domain' => 'example.test',
        'password' => '{SSHA512}placeholder',
        'enablesogowebmail' => true,
        'enablesogocalendar' => false,
        'enablesogo' => true,
    ]);

    $raw = DB::connection('vmail')->table('mailbox')
        ->where('username', 'someone@example.test')->first();

    // Writing 1 here breaks SOGo silently, which is why these three columns
    // are a character type in both schema files.
    expect($raw->enablesogowebmail)->toBe('y')
        ->and($raw->enablesogocalendar)->toBe('n')
        // enablesogo is an ordinary integer flag, not one of the three.
        ->and((int) $raw->enablesogo)->toBe(1);
});

it('never lets the password reach an array or a response', function () {
    Domain::create(['domain' => 'example.test', 'active' => true]);
    Mailbox::create([
        'username' => 'someone@example.test',
        'domain' => 'example.test',
        'password' => '{SSHA512}secret',
    ]);

    $mailbox = Mailbox::query()->find('someone@example.test');

    expect($mailbox->toArray())->not->toHaveKey('password')
        ->and($mailbox->password)->toBe('{SSHA512}secret');
});

it('refuses to write the tables Dovecot maintains', function () {
    expect(fn () => UsedQuota::query()->find('someone@example.test')?->delete())
        ->not->toThrow(Exception::class);

    $quota = new UsedQuota;
    $quota->username = 'someone@example.test';

    expect(fn () => $quota->save())
        ->toThrow(ReadOnlyMailTable::class);
});
