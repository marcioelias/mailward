<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

/**
 * An unsalted digest, stored as lower case hexadecimal — `{PLAIN-MD5}`.
 *
 * Present only on old accounts. Mailward verifies it so those accounts can log
 * in and have their password rewritten into the configured scheme; it is never
 * generated.
 */
final class PlainDigestScheme implements Scheme
{
    public function __construct(
        private readonly string $label,
        private readonly string $algorithm,
    ) {}

    public function name(): string
    {
        return $this->label;
    }

    public function handles(string $storedWithoutPrefix): bool
    {
        return (bool) preg_match('/^[0-9a-f]+$/i', $storedWithoutPrefix);
    }

    public function verify(string $plain, string $storedWithoutPrefix): bool
    {
        return hash_equals(
            strtolower($storedWithoutPrefix),
            hash($this->algorithm, $plain),
        );
    }

    public function hash(string $plain): string
    {
        return hash($this->algorithm, $plain);
    }

    public function isSafeToGenerate(): bool
    {
        return false;
    }
}
