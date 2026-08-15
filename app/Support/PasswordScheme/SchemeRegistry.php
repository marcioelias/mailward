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
