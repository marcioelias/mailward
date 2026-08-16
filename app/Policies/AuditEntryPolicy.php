<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mail\Mailbox;

/**
 * Who may read the audit log.
 *
 * `audit_log` is a Mailward-owned entity rather than a domain-owned resource,
 * so the per-domain scope rule of `docs/policies/authorization.md` §2 does not
 * reach it as written. Rather than stretch that rule to a resource it was not
 * written for, v1 puts the whole log behind the global-admin flag
 * (`docs/features/audit-log.md` BR-16, Q3=A).
 *
 * **A domain admin cannot read it at all** — not the entries whose target lies
 * inside a domain they administer, and not even the entries they themselves
 * produced. The cost is real and is stated rather than hidden: they cannot see
 * who probed their own domain, and in v1 that question is answered by asking a
 * global admin. What it buys is that no `target_type` needs a target → domain
 * resolver, and the entries that have no domain at all — sign-ins, `settings`
 * changes, domain-admin assignments, console runs — need no rule of their own.
 * Widening this later is purely additive.
 *
 * There is no `view`, `create`, `update` or `delete` method, and their absence
 * is the point: the log is read-only and has no per-entry contract
 * (BR-05, and Contracts — no route resolves for an entry).
 */
final class AuditEntryPolicy
{
    public function viewAny(Mailbox $mailbox): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }
}
