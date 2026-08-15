<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when Mailward attempts a write that the iRedMail schema forbids.
 *
 * Both schema files carry the constraint as a comment only — nothing in the
 * database prevents the write, and the damage (a quota figure Dovecot then
 * disagrees with, a row updated by a key MySQL does not guarantee to be
 * unique) is silent. The models turn those comments into an exception.
 */
final class ReadOnlyMailTable extends RuntimeException
{
    public static function maintainedByDovecot(string $table): self
    {
        return new self(
            "The `{$table}` table is maintained by Dovecot and is read-only for Mailward."
        );
    }

    public static function insertOnly(string $table): self
    {
        return new self(
            "The `{$table}` table is insert-only: MySQL declares no primary key or unique index on it, so no row can be safely updated or deleted by key."
        );
    }
}
