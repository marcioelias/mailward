<?php

declare(strict_types=1);

namespace App\Policies\Mail;

use App\Models\Mail\AliasDomain;
use App\Models\Mail\Mailbox;
use App\Support\Authorization\Actor;

/**
 * `docs/features/alias-domains.md` BR-12: every write here is global-admin
 * only. Creating or deleting an alias domain adds or removes a name from the
 * mail server's namespace, which is not scoped to a domain the actor already
 * administers, so the scope rule cannot authorise it.
 *
 * Visibility is scoped, and BR-05 resolves an alias domain's domain as its
 * `target_domain` — the only column here that names a row in `domain`.
 */
final class AliasDomainPolicy
{
    public function viewAny(Mailbox $mailbox): bool
    {
        return true;
    }

    public function view(Mailbox $mailbox, AliasDomain $aliasDomain): bool
    {
        return (new Actor($mailbox))->administers((string) $aliasDomain->target_domain);
    }

    public function create(Mailbox $mailbox): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }

    public function update(Mailbox $mailbox, AliasDomain $aliasDomain): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }

    public function delete(Mailbox $mailbox, AliasDomain $aliasDomain): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }

    public function toggle(Mailbox $mailbox, AliasDomain $aliasDomain): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }
}
