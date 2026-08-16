<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Mail\DomainAdmin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * docs/features/domain-admins.md, Contracts §Console — the escape hatch
 * required by BR-A04. AC-15 to AC-17, AC-24 to AC-26.
 *
 * These need no routes: the command exists precisely so that a lockout caused
 * by the web interface can be repaired without the web interface.
 */
beforeEach(function () {
    foreach (['mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }

    AuditEntry::query()->delete();
});

function promotableMailbox(string $address, array $attributes = []): void
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
        'isadmin' => 0,
        'isglobaladmin' => 0,
        'active' => 1,
        'created' => now(), 'modified' => now(), 'expired' => '9999-12-31 00:00:00',
        ...$attributes,
    ]);
}

function mailboxFlag(string $address, string $column): int
{
    return (int) DB::connection('vmail')->table('mailbox')
        ->where('username', $address)->value($column);
}

function sentinelRows(string $address): int
{
    return DB::connection('vmail')->table('domain_admins')
        ->where('username', $address)
        ->where('domain', DomainAdmin::ALL_DOMAINS)
        ->count();
}

describe('promoting (AC-15)', function () {
    it('writes both flags and the ALL row, and reports before and after', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true])
            ->expectsOutputToContain('user@example.test')
            ->expectsOutputToContain('mailbox.isglobaladmin')
            ->assertExitCode(0);

        expect(mailboxFlag('user@example.test', 'isadmin'))->toBe(1)
            ->and(mailboxFlag('user@example.test', 'isglobaladmin'))->toBe(1)
            ->and(sentinelRows('user@example.test'))->toBe(1);
    });

    it('lower-cases the address before looking it up and writing it (BR-08)', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => '  User@Example.TEST ', '--global' => true])
            ->assertExitCode(0);

        expect(sentinelRows('user@example.test'))->toBe(1);
    });

    it('promotes an account that is inactive and expired', function () {
        // A recovery tool that refuses to run on a broken install is not a
        // recovery tool (Contracts, Console).
        promotableMailbox('locked@example.test', ['active' => 0, 'expired' => '2000-01-01 00:00:00']);

        $this->artisan('mailward:promote', ['address' => 'locked@example.test', '--global' => true])
            ->assertExitCode(0);

        expect(mailboxFlag('locked@example.test', 'isglobaladmin'))->toBe(1);
    });

    it('writes the never-expires sentinel and active = 1 on the ALL row (BR-09, BR-10)', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true]);

        $row = DB::connection('vmail')->table('domain_admins')
            ->where('username', 'user@example.test')->first();

        expect((int) $row->active)->toBe(1)
            ->and($row->created)->not->toBeNull()
            ->and($row->modified)->not->toBeNull()
            ->and((string) $row->expired)->toStartWith('9999-12-31');
    });
});

describe('refusing (AC-16, AC-24, AC-25)', function () {
    it('refuses without --global and writes nothing (BR-17, AC-24)', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test'])
            ->assertExitCode(1);

        expect(mailboxFlag('user@example.test', 'isadmin'))->toBe(0)
            ->and(mailboxFlag('user@example.test', 'isglobaladmin'))->toBe(0)

            // In particular, no per-domain grant is created: there is no such
            // form of this command.
            ->and(DB::connection('vmail')->table('domain_admins')
                ->where('username', 'user@example.test')->count())->toBe(0);
    });

    it('defines --global and no --domain option (BR-17, AC-25)', function () {
        $definition = $this->app->make(Kernel::class)
            ->all()['mailward:promote']->getDefinition();

        expect($definition->hasOption('global'))->toBeTrue()
            ->and($definition->hasOption('domain'))->toBeFalse();
    });

    it('fails on --domain as an unknown option and writes nothing (AC-25)', function () {
        promotableMailbox('user@example.test');

        expect(fn () => $this->artisan('mailward:promote', [
            'address' => 'user@example.test',
            '--global' => true,
            '--domain' => 'example.test',
        ])->run())->toThrow(InvalidOptionException::class);

        expect(DB::connection('vmail')->table('domain_admins')
            ->where('username', 'user@example.test')->count())->toBe(0);
    });

    it('fails when no mailbox exists at that address (AC-16)', function () {
        $this->artisan('mailward:promote', ['address' => 'nobody@example.test', '--global' => true])
            ->assertExitCode(1);

        expect(DB::connection('vmail')->table('mailbox')->count())->toBe(0)
            ->and(DB::connection('vmail')->table('domain_admins')->count())->toBe(0);
    });

    it('rejects an address that cannot be stored in ascii (BR-07)', function () {
        $this->artisan('mailward:promote', ['address' => 'usuário@example.test', '--global' => true])
            ->assertExitCode(1);

        expect(DB::connection('vmail')->table('domain_admins')->count())->toBe(0);
    });
});

describe('idempotence (AC-17)', function () {
    it('re-runs on an existing global admin without a duplicate-key error', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true])
            ->assertExitCode(0);

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true])
            ->assertExitCode(0);

        expect(sentinelRows('user@example.test'))->toBe(1);
    });

    it('repairs a drifted pair that has the flag but no ALL row (BR-19)', function () {
        promotableMailbox('drifted@example.test', ['isadmin' => 1, 'isglobaladmin' => 1]);

        $this->artisan('mailward:promote', ['address' => 'drifted@example.test', '--global' => true])
            ->assertExitCode(0);

        expect(sentinelRows('drifted@example.test'))->toBe(1);
    });
});

describe('audit (AC-26, BR-18)', function () {
    it('records one entry under the console actor, not the promoted address', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true]);

        $entries = AuditEntry::query()->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->actor)->toBe(AuditEntry::CONSOLE_ACTOR)
            ->and($entries->first()->actor)->not->toBe('user@example.test');
    });

    it('records the invoking OS user and host in place of the IP', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true]);

        $entry = AuditEntry::query()->latest('id')->first();

        expect($entry->ip_address)->toContain('@')
            ->and($entry->ip_address)->not->toBe('127.0.0.1');
    });

    it('records the before and after values of both representations', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test', '--global' => true]);

        $entry = AuditEntry::query()->latest('id')->first();

        expect($entry->properties['old']['isglobaladmin'])->toBeFalse()
            ->and($entry->properties['old']['sentinelRow'])->toBeFalse()
            ->and($entry->properties['attributes']['isglobaladmin'])->toBeTrue()
            ->and($entry->properties['attributes']['sentinelRow'])->toBeTrue();
    });

    it('writes no audit entry when it refuses (BR-17)', function () {
        promotableMailbox('user@example.test');

        $this->artisan('mailward:promote', ['address' => 'user@example.test'])->assertExitCode(1);

        expect(AuditEntry::query()->count())->toBe(0);
    });
});
