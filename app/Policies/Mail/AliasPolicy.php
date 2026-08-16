<?php

declare(strict_types=1);

namespace App\Policies\Mail;

use App\Models\Mail\Alias;
use App\Models\Mail\Mailbox;
use App\Support\Authorization\Actor;

/**
 * Standalone aliases are the *contents* of a domain, so — like mailboxes and
 * unlike the domain record itself — a domain admin may write them within their
 * scope (`docs/policies/authorization.md` §2,
 * `docs/features/aliases.md` BR-12).
 *
 * The domain that decides every question here is `alias.domain`, and only that.
 * A member row is scoped by the alias it belongs to, never by the member's own
 * domain: a member may sit in a domain the actor does not administer, or off
 * the server entirely, and authorisation is never decided on a destination
 * (BR-12, BR-19). There is therefore no ability here for a member — adding and
 * removing one is an update of the alias that owns it.
 */
final class AliasPolicy
{
    public function viewAny(Mailbox $mailbox): bool
    {
        return true;
    }

    public function view(Mailbox $actor, Alias $alias): bool
    {
        return (new Actor($actor))->administers((string) $alias->domain);
    }

    public function create(Mailbox $actor): bool
    {
        // Any administrator may create inside a domain they administer; which
        // domains those are is decided by the scope, not here.
        return (bool) $actor->isadmin || (bool) $actor->isglobaladmin;
    }

    public function update(Mailbox $actor, Alias $alias): bool
    {
        return $this->view($actor, $alias);
    }

    public function delete(Mailbox $actor, Alias $alias): bool
    {
        return $this->view($actor, $alias);
    }
}
