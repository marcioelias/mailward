<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Mail\Mailbox;

/**
 * Whether an account may hold a panel session.
 *
 * `docs/policies/authorization.md` §6 makes this a security boundary rather
 * than a convenience: every mail account on the server has a valid password,
 * so authenticating proves nothing about being allowed in.
 *
 * It lives in one place because it is asked twice — once at login, and once
 * on every request afterwards (Q13, answered 2026-08-15). Two copies of a
 * rule this consequential would eventually disagree.
 */
final class AdministratorGate
{
    public static function passes(Mailbox $mailbox): bool
    {
        if (! $mailbox->active) {
            return false;
        }

        /*
         * Expiry is a comparison against now, never equality against a
         * sentinel: the two drivers ship different "never expires" values
         * (docs/reference/schema-type-matrix.md, D2).
         */
        if ($mailbox->expired !== null && ! $mailbox->expired->isFuture()) {
            return false;
        }

        return (bool) $mailbox->isadmin || (bool) $mailbox->isglobaladmin;
    }
}
