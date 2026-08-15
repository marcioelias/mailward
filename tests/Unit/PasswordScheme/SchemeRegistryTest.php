<?php

declare(strict_types=1);

use App\Support\PasswordScheme\CannotGeneratePassword;
use App\Support\PasswordScheme\SchemeRegistry;
use App\Support\PasswordScheme\UnsupportedScheme;

/**
 * The password column is the account's real mail password, shared with Dovecot.
 * Getting verification wrong in either direction is a security failure: too
 * strict locks an administrator out of a panel while their mail keeps working,
 * too loose admits a password IMAP would have rejected.
 */
function registry(string $generates = 'SSHA512'): SchemeRegistry
{
    return new SchemeRegistry($generates);
}

/** Build a stored value the way Dovecot does: base64(digest . salt). */
function saltedDigest(string $label, string $algorithm, string $plain, string $salt): string
{
    return '{'.$label.'}'.base64_encode(hash($algorithm, $plain.$salt, true).$salt);
}

describe('verification of schemes a legacy account may carry', function () {
    it('verifies SSHA512, the scheme a current linux iRedMail generates', function () {
        $stored = saltedDigest('SSHA512', 'sha512', 'correct horse', 'somesalt');

        expect(registry()->verify('correct horse', $stored))->toBeTrue()
            ->and(registry()->verify('wrong horse', $stored))->toBeFalse();
    });

    it('verifies SSHA and SSHA256', function () {
        expect(registry()->verify('hunter2', saltedDigest('SSHA', 'sha1', 'hunter2', 'abcdefgh')))->toBeTrue()
            ->and(registry()->verify('hunter2', saltedDigest('SSHA256', 'sha256', 'hunter2', 'abcdefgh')))->toBeTrue();
    });

    it('derives the salt length from the value rather than assuming one', function () {
        // Different installs choose different salt lengths, and the length is
        // recorded nowhere. Both must verify.
        expect(registry()->verify('pw', saltedDigest('SSHA512', 'sha512', 'pw', 'four')))->toBeTrue()
            ->and(registry()->verify('pw', saltedDigest('SSHA512', 'sha512', 'pw', 'sixteen_byte_salt')))->toBeTrue();
    });

    it('verifies bcrypt under both labels it appears as', function () {
        $bcrypt = password_hash('hunter2', PASSWORD_BCRYPT);

        // doveadm writes {BLF-CRYPT}; iRedMail's tables document {CRYPT}$2a$.
        expect(registry()->verify('hunter2', '{BLF-CRYPT}'.$bcrypt))->toBeTrue()
            ->and(registry()->verify('hunter2', '{CRYPT}'.$bcrypt))->toBeTrue()
            ->and(registry()->verify('wrong', '{CRYPT}'.$bcrypt))->toBeFalse();
    });

    it('verifies sha512-crypt', function () {
        $stored = crypt('hunter2', '$6$'.base64_encode(random_bytes(12)));

        expect(registry()->verify('hunter2', '{CRYPT}'.$stored))->toBeTrue();
    });

    it('verifies PLAIN-MD5', function () {
        expect(registry()->verify('hunter2', '{PLAIN-MD5}'.md5('hunter2')))->toBeTrue()
            ->and(registry()->verify('nope', '{PLAIN-MD5}'.md5('hunter2')))->toBeFalse();
    });

    it('verifies cleartext rows, because they exist on real servers', function () {
        expect(registry()->verify('hunter2', '{PLAIN}hunter2'))->toBeTrue()
            ->and(registry()->verify('hunter2', '{CLEARTEXT}hunter2'))->toBeTrue();
    });
});

it('verifies an unprefixed hash, which iRedMail treats as CRYPT', function () {
    // This is the one that matters. iRedMail has shipped
    // default_pass_scheme = CRYPT, so a bare hash is a valid password the mail
    // server accepts. Demanding a {SCHEME} prefix would lock out accounts that
    // still collect mail.
    $bare = password_hash('hunter2', PASSWORD_BCRYPT);

    expect(registry()->verify('hunter2', $bare))->toBeTrue()
        ->and(registry()->verify('wrong', $bare))->toBeFalse();
});

it('reads the scheme label case-insensitively', function () {
    $stored = saltedDigest('ssha512', 'sha512', 'pw', 'salt1234');

    expect(registry()->verify('pw', $stored))->toBeTrue();
});

describe('failure modes', function () {
    it('refuses rather than denying silently when it cannot read the scheme', function () {
        // Returning false here would tell an administrator their password is
        // wrong when it is not (docs/decisions/0007).
        expect(fn () => registry()->verify('hunter2', '{SCRAM-SHA-256}whatever'))
            ->toThrow(UnsupportedScheme::class);
    });

    it('reports whether a value is readable without attempting it', function () {
        expect(registry()->canVerify('{SSHA512}abc'))->toBeTrue()
            ->and(registry()->canVerify('{SCRAM-SHA-256}abc'))->toBeFalse()
            ->and(registry()->canVerify('$2y$10$whatever'))->toBeTrue();
    });

    it('treats an empty password as unusable, never as a match', function () {
        expect(registry()->verify('', ''))->toBeFalse()
            ->and(registry()->verify('anything', ''))->toBeFalse();
    });

    it('fails instead of erroring on a corrupt row', function () {
        expect(registry()->verify('hunter2', '{SSHA512}not-valid-base64!!'))->toBeFalse()
            ->and(registry()->verify('hunter2', '{CRYPT}$6$truncated'))->toBeFalse();
    });
});

describe('generation', function () {
    it('generates only the configured scheme, prefixed', function () {
        $hashed = registry('SSHA512')->hash('hunter2');

        expect($hashed)->toStartWith('{SSHA512}')
            ->and(registry()->verify('hunter2', $hashed))->toBeTrue();
    });

    it('salts every hash differently', function () {
        expect(registry()->hash('hunter2'))->not->toBe(registry()->hash('hunter2'));
    });

    it('can be configured to generate bcrypt instead', function () {
        $hashed = registry('BLF-CRYPT')->hash('hunter2');

        expect($hashed)->toStartWith('{BLF-CRYPT}')
            ->and(registry()->verify('hunter2', $hashed))->toBeTrue();
    });

    it('refuses to write a cleartext password', function () {
        expect(fn () => registry('PLAIN')->hash('hunter2'))
            ->toThrow(CannotGeneratePassword::class);
    });

    it('refuses to generate a scheme it could not read back', function () {
        expect(fn () => registry('SCRAM-SHA-256')->hash('hunter2'))
            ->toThrow(UnsupportedScheme::class);
    });

    it('recognises a legacy row as not being in the current scheme', function () {
        expect(registry('SSHA512')->isCurrent('{SSHA512}abc'))->toBeTrue()
            ->and(registry('SSHA512')->isCurrent('{PLAIN-MD5}abc'))->toBeFalse()
            ->and(registry('SSHA512')->isCurrent('$2y$10$bare'))->toBeFalse();
    });
});

it('refuses to be configured to generate an unsalted digest', function () {
    // Verifying PLAIN-MD5 is mandatory — such rows exist and their owners must
    // be able to sign in. Writing one is not: it would leave an account weaker
    // than it was before Mailward touched it.
    expect(registry()->verify('hunter2', '{PLAIN-MD5}'.md5('hunter2')))->toBeTrue()
        ->and(fn () => registry('PLAIN-MD5')->hash('hunter2'))
        ->toThrow(CannotGeneratePassword::class);
});
