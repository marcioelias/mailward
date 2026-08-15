<?php

declare(strict_types=1);

namespace App\Support\PasswordScheme;

/**
 * The password stored as-is — `{PLAIN}` and `{CLEARTEXT}`.
 *
 * Verified because such rows exist on real servers and their owners must be
 * able to log in. Generating one is refused outright: Mailward will not be the
 * thing that writes a cleartext mail password.
 */
final class PlainTextScheme implements Scheme
{
    public function __construct(private readonly string $label = 'PLAIN') {}

    public function name(): string
    {
        return $this->label;
    }

    public function handles(string $storedWithoutPrefix): bool
    {
        return $storedWithoutPrefix !== '';
    }

    public function verify(string $plain, string $storedWithoutPrefix): bool
    {
        return hash_equals($storedWithoutPrefix, $plain);
    }

    public function hash(string $plain): string
    {
        throw new CannotGeneratePassword(
            'Mailward refuses to write a cleartext password. Configure a hashed scheme in config/mailward.php.'
        );
    }
}
