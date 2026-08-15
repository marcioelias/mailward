<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

/**
 * Every password format Mailward can read, and the one it writes.
 *
 * Verification is exhaustive by design and generation is a single configured
 * choice (docs/decisions/0007-configurable-maildir-and-password-scheme.md).
 */
final class SchemeRegistry
{
    /**
     * The bcrypt work factor below which a stored password is reported as
     * weak. PHP's own default is 12; a real install carries accounts at 5.
     */
    private const MINIMUM_BCRYPT_COST = 10;

    /** @var array<string, Scheme> keyed by upper case label */
    private array $schemes = [];

    public function __construct(private readonly string $generatingScheme)
    {
        $this->register(new SaltedDigestScheme('SSHA512', 'sha512', 64));
        $this->register(new SaltedDigestScheme('SSHA256', 'sha256', 32));
        $this->register(new SaltedDigestScheme('SSHA', 'sha1', 20));
        $this->register(new CryptScheme('CRYPT'));

        // doveadm emits this label for bcrypt; iRedMail's own tables call the
        // same thing {CRYPT}$2a$. Both must resolve.
        $this->register(new CryptScheme('BLF-CRYPT'));
        $this->register(new CryptScheme('SHA512-CRYPT'));
        $this->register(new CryptScheme('SHA256-CRYPT'));
        $this->register(new CryptScheme('MD5-CRYPT'));

        $this->register(new PlainDigestScheme('PLAIN-MD5', 'md5'));
        $this->register(new PlainTextScheme('PLAIN'));
        $this->register(new PlainTextScheme('CLEARTEXT'));
    }

    public static function fromConfig(): self
    {
        return new self((string) config('mailward.password.scheme', 'SSHA512'));
    }

    private function register(Scheme $scheme): void
    {
        $this->schemes[strtoupper($scheme->name())] = $scheme;
    }

    /**
     * Verify a plaintext password against a value read from
     * `vmail.mailbox.password`, prefix and all.
     *
     * @throws UnsupportedScheme when the row names a scheme we cannot read
     */
    public function verify(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        [$label, $payload] = self::split($stored);

        if ($label === null) {
            /*
             * No {SCHEME} prefix. iRedMail has shipped default_pass_scheme =
             * CRYPT, so this is a valid password the mail server accepts, and
             * crypt() reads the algorithm from the value's own $id$ magic.
             */
            return (new CryptScheme)->verify($plain, $payload);
        }

        $scheme = $this->schemes[$label] ?? throw UnsupportedScheme::named($label);

        return $scheme->verify($plain, $payload);
    }

    /**
     * Produce a value ready to be written to `vmail.mailbox.password`,
     * including its `{SCHEME}` prefix.
     */
    public function hash(string $plain): string
    {
        $label = strtoupper($this->generatingScheme);
        $scheme = $this->schemes[$label] ?? throw UnsupportedScheme::named($label);

        if (! $scheme->isSafeToGenerate()) {
            throw new CannotGeneratePassword(
                "Mailward verifies {{$label}} so legacy accounts can sign in, but will not write it: "
                .'an unsalted or cleartext password is weaker than what the account already had. '
                .'Set MAILWARD_PASSWORD_SCHEME to a salted scheme such as SSHA512 or BLF-CRYPT.'
            );
        }

        return '{'.$scheme->name().'}'.$scheme->hash($plain);
    }

    /**
     * Whether a stored value is in the scheme Mailward currently generates.
     * A false answer is not an error — it is a legacy account, and rewriting
     * it is a decision the caller makes, not this class.
     */
    public function isCurrent(string $stored): bool
    {
        [$label] = self::split($stored);

        return $label === strtoupper($this->generatingScheme);
    }

    public function canVerify(string $stored): bool
    {
        [$label] = self::split($stored);

        return $label === null || isset($this->schemes[$label]);
    }

    /**
     * Why a stored password is weaker than one Mailward would write today, or
     * null when it is not.
     *
     * The scheme label is not enough on its own. A bcrypt hash at cost 5 and
     * one at cost 12 are both `{CRYPT}$2a$`, and 5 is thirty-two iterations
     * against a thousand — the label says they are the same and they are not.
     * A real install measured 84 accounts at cost 5 alongside 435 at 10 and 12
     * (`docs/reference/observed-install.md`).
     *
     * This reports; it never rewrites. Rewriting a mail password is a
     * deliberate act, because the column is the account's real password and
     * changing it changes IMAP, SMTP and webmail at once
     * (`docs/02-domain.md` §4).
     */
    public function weakness(string $stored): ?string
    {
        if ($stored === '') {
            return 'The account has no password set.';
        }

        if (! $this->canVerify($stored)) {
            return 'The scheme is one Mailward cannot verify, so this account cannot sign in.';
        }

        [$label, $payload] = self::split($stored);

        $cost = self::bcryptCost($payload);

        if ($cost !== null && $cost < self::MINIMUM_BCRYPT_COST) {
            return sprintf(
                'bcrypt at cost %d, below the %d Mailward writes — %d iterations against %d.',
                $cost,
                self::MINIMUM_BCRYPT_COST,
                2 ** $cost,
                2 ** self::MINIMUM_BCRYPT_COST,
            );
        }

        if ($cost !== null) {
            return null;
        }

        /*
         * Everything that is not bcrypt and not the generating scheme. The
         * MD5 family is the one that bites in practice: Dovecot 2.4 disables
         * it by default, so such an account verifies here and fails at IMAP.
         */
        if (str_starts_with($payload, '$1$') || $label === 'PLAIN-MD5' || $label === 'MD5-CRYPT') {
            return 'md5crypt, which Dovecot 2.4 disables by default — this account may already be unable to collect mail.';
        }

        if ($label === 'PLAIN' || $label === 'CLEARTEXT') {
            return 'The password is stored in clear text.';
        }

        return $this->isCurrent($stored) ? null : 'An older scheme than the one Mailward writes.';
    }

    /**
     * The work factor of a bcrypt hash, or null when the value is not bcrypt.
     */
    private static function bcryptCost(string $payload): ?int
    {
        if (preg_match('/^\$2[abxy]?\$(\d{2})\$/', $payload, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: string} the upper case label, or null when
     *                                      the value carries no prefix, and the remaining payload
     */
    private static function split(string $stored): array
    {
        if (preg_match('/^\{([A-Za-z0-9-]+)\}(.*)$/s', $stored, $matches) === 1) {
            return [strtoupper($matches[1]), $matches[2]];
        }

        return [null, $stored];
    }
}
