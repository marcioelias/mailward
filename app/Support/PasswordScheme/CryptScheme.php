<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

/**
 * The `crypt(3)` family, covering everything whose stored value carries its own
 * `$id$` magic: `$6$` SHA-512, `$5$` SHA-256, `$2a$`/`$2b$`/`$2y$` bcrypt and
 * `$1$` MD5.
 *
 * This is also the scheme that answers for **unprefixed** rows. iRedMail has
 * shipped `default_pass_scheme = CRYPT`, so a bare hash with no `{SCHEME}`
 * label is a valid password the mail server accepts, and a verifier that
 * demanded a prefix would lock out accounts that still collect mail
 * (docs/reference/current-iredmail-behaviour.md §Q2).
 *
 * Two labels reach bcrypt: iRedMail's own tables document it as `{CRYPT}$2a$`
 * while `doveadm` emits `{BLF-CRYPT}`. Both are accepted.
 *
 * A caveat worth knowing rather than discovering: Dovecot 2.4 disables the MD5
 * family and DES by default, so a `$1$` row can verify here and still fail at
 * IMAP. That asymmetry is why verification failures are reported rather than
 * silently accepted.
 */
final class CryptScheme implements Scheme
{
    public function __construct(private readonly string $label = 'CRYPT') {}

    public function name(): string
    {
        return $this->label;
    }

    public function handles(string $storedWithoutPrefix): bool
    {
        return str_starts_with($storedWithoutPrefix, '$');
    }

    public function verify(string $plain, string $storedWithoutPrefix): bool
    {
        if ($storedWithoutPrefix === '') {
            return false;
        }

        $computed = crypt($plain, $storedWithoutPrefix);

        /*
         * crypt() returns a short failure string rather than throwing when it
         * cannot parse the salt. Anything under 13 characters is that failure,
         * never a real hash.
         */
        if (strlen($computed) < 13) {
            return false;
        }

        return hash_equals($storedWithoutPrefix, $computed);
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public function isSafeToGenerate(): bool
    {
        return true;
    }
}
