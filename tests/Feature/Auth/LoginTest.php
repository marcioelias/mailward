<?php

declare(strict_types=1);

use App\Actions\AuthenticateAdministratorAction;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * The login form is a password oracle against every real mail account on the
 * server. These cover the gate in docs/policies/authorization.md §6, and every
 * denial must be indistinguishable from every other.
 */
beforeEach(function () {
    DB::connection('vmail')->table('mailbox')->delete();
    DB::connection('vmail')->table('domain')->delete();
    RateLimiter::clear('');

    Domain::create(['domain' => 'example.test', 'active' => true]);
});

afterEach(function () {
    DB::connection('vmail')->table('mailbox')->delete();
    DB::connection('vmail')->table('domain')->delete();
});

function makeMailbox(array $attributes = []): Mailbox
{
    return Mailbox::create([
        'username' => 'admin@example.test',
        'domain' => 'example.test',
        'password' => app(SchemeRegistry::class)->hash('correct horse'),
        'isadmin' => true,
        'active' => true,
        ...$attributes,
    ]);
}

/** Every denial must produce this same outcome, whatever the real reason. */
function assertDenied(TestResponse $response): void
{
    $response->assertRedirect();
    expect(auth()->check())->toBeFalse();
}

it('shows the login page to a guest without revealing anything', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('signs in an administrator with the right password', function () {
    makeMailbox();

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()?->getAuthIdentifier())->toBe('admin@example.test');
});

it('signs in a global admin too', function () {
    makeMailbox(['isadmin' => false, 'isglobaladmin' => true]);

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

    expect(auth()->check())->toBeTrue();
});

it('canonicalises the submitted address to lower case', function () {
    // ADR-0005: normalisation applies to lookups, not only to writes.
    makeMailbox();

    $this->post('/login', ['email' => '  Admin@Example.TEST ', 'password' => 'correct horse']);

    expect(auth()->check())->toBeTrue();
});

it('rejects a wrong password', function () {
    makeMailbox();

    assertDenied($this->post('/login', [
        'email' => 'admin@example.test', 'password' => 'wrong horse',
    ]));
});

it('rejects an address that does not exist', function () {
    assertDenied($this->post('/login', [
        'email' => 'nobody@example.test', 'password' => 'correct horse',
    ]));
});

it('rejects a valid mail account that is not an administrator', function () {
    // This is the critical one. The password is correct and the account is a
    // real mailbox — every mail user on the server would pass step one.
    makeMailbox(['isadmin' => false, 'isglobaladmin' => false]);

    assertDenied($this->post('/login', [
        'email' => 'admin@example.test', 'password' => 'correct horse',
    ]));
});

it('rejects an inactive administrator', function () {
    makeMailbox(['active' => false]);

    assertDenied($this->post('/login', [
        'email' => 'admin@example.test', 'password' => 'correct horse',
    ]));
});

it('rejects an expired administrator', function () {
    makeMailbox(['expired' => now()->subDay()]);

    assertDenied($this->post('/login', [
        'email' => 'admin@example.test', 'password' => 'correct horse',
    ]));
});

it('accepts an administrator whose expiry is the never-expires sentinel', function () {
    // The two drivers ship different sentinels, so expiry is a comparison
    // against now rather than equality (schema-type-matrix D2).
    makeMailbox(['expired' => null]);

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

    expect(auth()->check())->toBeTrue();
});

it('gives the identical message whatever the reason for denial', function () {
    makeMailbox(['isadmin' => false, 'isglobaladmin' => false]);

    // One known message, asserted for each distinct reason. Correct
    // credentials on a non-administrator, a wrong password, and an address
    // that does not exist are indistinguishable to the caller.
    $generic = __('auth.failed');

    expect($generic)->not->toBe('auth.failed'); // the translation must resolve

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse'])
        ->assertSessionHasErrors(['email' => $generic]);

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $generic]);

    $this->post('/login', ['email' => 'ghost@example.test', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $generic]);
});

it('verifies a legacy password scheme, not only the one it generates', function () {
    // An account whose password predates the current scheme must still be able
    // to sign in (docs/decisions/0007).
    makeMailbox(['password' => '{PLAIN-MD5}'.md5('correct horse')]);

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

    expect(auth()->check())->toBeTrue();
});

it('verifies an unprefixed hash, which iRedMail treats as CRYPT', function () {
    makeMailbox(['password' => password_hash('correct horse', PASSWORD_BCRYPT)]);

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

    expect(auth()->check())->toBeTrue();
});

it('denies rather than errors when the stored scheme cannot be read', function () {
    makeMailbox(['password' => '{SCRAM-SHA-256}unreadable']);

    assertDenied($this->post('/login', [
        'email' => 'admin@example.test', 'password' => 'correct horse',
    ]));
});

it('throttles after five attempts in a minute', function () {
    makeMailbox();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'wrong'])
            ->assertRedirect();
    }

    // The sixth is refused with 429 even though the password is now correct
    // (docs/features/authentication.md, Contracts).
    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse'])
        ->assertStatus(429);

    expect(auth()->check())->toBeFalse();
});

it('signs out', function () {
    makeMailbox();
    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);
    expect(auth()->check())->toBeTrue();

    $this->post('/logout')->assertRedirect();

    expect(auth()->check())->toBeFalse();
});

it('canonicalises the address inside the action too, not only at the request', function () {
    // Without this the lookup is driver-dependent: MySQL's collation matches a
    // mixed-case address by accident, PostgreSQL does not. A console command
    // or a test reaching the action directly must behave the same on both.
    makeMailbox();

    $action = app(AuthenticateAdministratorAction::class);

    expect($action->handle('ADMIN@EXAMPLE.TEST', 'correct horse'))->not->toBeNull()
        ->and($action->handle('  admin@example.test  ', 'correct horse'))->not->toBeNull();
});

it('restores the administrator from the session on a later request', function () {
    // The regression this exists for returned 502, not an exception: resolving
    // the actor went through the domain scope, which asks who the actor is, and
    // recursed until the process died.
    //
    // forgetUser() is the whole point. Without it the guard still holds the
    // user in memory from the login request and never reaches the provider —
    // which is exactly why the original test passed while a browser could not
    // load a single page after signing in.
    makeMailbox();

    $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse'])
        ->assertRedirect('/');

    auth()->forgetUser();

    $this->get('/')->assertOk();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()?->getAuthIdentifier())->toBe('admin@example.test');
});

describe('the gate runs on every request, not only at login (Q13)', function () {
    /**
     * Without this, demoting an administrator leaves them working inside the
     * panel until they choose to sign out.
     */
    function signInThen(array $revocation): void
    {
        makeMailbox();

        test()->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

        Mailbox::query()->withoutDomainScope()
            ->where('username', 'admin@example.test')
            ->update($revocation);

        auth()->forgetUser();
    }

    it('ends the session when the administrator flag is cleared', function () {
        signInThen(['isadmin' => 0, 'isglobaladmin' => 0]);

        $this->get('/')->assertRedirect('/login');

        expect(auth()->check())->toBeFalse();
    });

    it('ends the session when the account is deactivated', function () {
        signInThen(['active' => 0]);

        $this->get('/')->assertRedirect('/login');
    });

    it('ends the session when the account expires', function () {
        signInThen(['expired' => now()->subMinute()]);

        $this->get('/')->assertRedirect('/login');
    });

    it('ends the session when the account is deleted outright', function () {
        makeMailbox();
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

        Mailbox::query()->withoutDomainScope()->where('username', 'admin@example.test')->delete();
        auth()->forgetUser();

        $this->get('/')->assertRedirect('/login');
    });

    it('leaves an untouched session alone', function () {
        makeMailbox();
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct horse']);

        auth()->forgetUser();

        $this->get('/')->assertOk();
        expect(auth()->check())->toBeTrue();
    });
});
