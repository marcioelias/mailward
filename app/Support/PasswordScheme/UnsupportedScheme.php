<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

use RuntimeException;

/**
 * Raised when a stored password carries a `{SCHEME}` Mailward cannot verify.
 *
 * This is deliberately loud. Silently returning "wrong password" would tell an
 * administrator their credentials are wrong when the truth is that Mailward
 * cannot read the format — and their password still works everywhere else on
 * the server (docs/decisions/0007).
 */
final class UnsupportedScheme extends RuntimeException
{
    public static function named(string $scheme): self
    {
        return new self("No verifier for password scheme {{$scheme}}.");
    }
}
