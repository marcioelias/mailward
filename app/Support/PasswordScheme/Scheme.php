<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

/**
 * One password format that may appear in `vmail.mailbox.password`.
 *
 * Mailward verifies every scheme a legacy account might carry and generates
 * only the one the server is configured for
 * (docs/01-architecture.md §5, docs/decisions/0007).
 */
interface Scheme
{
    /**
     * The `{SCHEME}` label this implementation answers to, without braces.
     */
    public function name(): string;

    /**
     * Whether this scheme can verify the given stored value. The value arrives
     * with its `{SCHEME}` prefix already stripped, except for the unprefixed
     * case, which no scheme claims by label.
     */
    public function handles(string $storedWithoutPrefix): bool;

    /**
     * Constant-time verification. Must never throw on malformed input — a
     * corrupt row is a failed login, not a server error.
     */
    public function verify(string $plain, string $storedWithoutPrefix): bool;

    /**
     * Produce a new hash, without the `{SCHEME}` prefix.
     */
    public function hash(string $plain): string;

    /**
     * Whether Mailward may be *configured* to write this scheme.
     *
     * Verification is always exhaustive — a legacy account must be able to
     * sign in whatever its password looks like. Generation is not: an
     * unsalted digest or a cleartext password is something Mailward will read
     * but never create, because writing one would weaken an account that was
     * previously fine.
     */
    public function isSafeToGenerate(): bool;
}
