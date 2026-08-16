<?php

declare(strict_types=1);

namespace App\Policies\Mail;

use App\Models\Mail\Mailbox;
use App\Support\Authorization\Actor;

/**
 * Mailboxes are the *contents* of a domain, so unlike the domain record itself
 * a domain admin may write them — within their scope
 * (`docs/policies/authorization.md` §2, `docs/features/domains.md` BR-20).
 *
 * The lockout rules are the sharp edge here. BR-A01 and BR-A02 in the
 * authorization policy forbid removing the last global admin and forbid an
 * administrator revoking their own global flag, and both are checked inside
 * the transaction that performs the change.
 */
final class MailboxPolicy
{
    public function viewAny(Mailbox $mailbox): bool
    {
        return true;
    }

    public function view(Mailbox $actor, Mailbox $target): bool
    {
        return (new Actor($actor))->administers((string) $target->domain);
    }

    public function create(Mailbox $actor): bool
    {
        // Any administrator may create inside a domain they administer; which
        // domains those are is decided by the scope, not here.
        return (bool) $actor->isadmin || (bool) $actor->isglobaladmin;
    }

    public function update(Mailbox $actor, Mailbox $target): bool
    {
        return $this->view($actor, $target);
    }

    public function delete(Mailbox $actor, Mailbox $target): bool
    {
        if (! $this->view($actor, $target)) {
            return false;
        }

        /*
         * BR-A02: an administrator cannot delete themselves out of the panel.
         * The last-global-admin rule (BR-A01) is enforced in the transaction
         * rather than here, because it depends on a count that must not change
         * between the check and the write.
         */
        return $actor->getAuthIdentifier() !== $target->getAuthIdentifier();
    }
}
