<?php

declare(strict_types=1);

namespace App\Policies\Mail;

use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Support\Authorization\Actor;

/**
 * The second barrier. The first is the domain scope in the query
 * (`docs/policies/authorization.md` §3); this decides individual records.
 *
 * Every write is global-admin only (`docs/features/domains.md` BR-19):
 * creating a domain adds a name to the mail server's namespace and deleting
 * one destroys every account inside it, and neither is scoped to a domain the
 * actor already administers, so the scope rule cannot authorise them.
 */
final class DomainPolicy
{
    public function viewAny(Mailbox $mailbox): bool
    {
        return true;
    }

    public function view(Mailbox $mailbox, Domain $domain): bool
    {
        return (new Actor($mailbox))->administers((string) $domain->getKey());
    }

    public function create(Mailbox $mailbox): bool
    {
        return $this->isGlobalAdmin($mailbox);
    }

    public function update(Mailbox $mailbox, Domain $domain): bool
    {
        return $this->isGlobalAdmin($mailbox);
    }

    public function delete(Mailbox $mailbox, Domain $domain): bool
    {
        return $this->isGlobalAdmin($mailbox);
    }

    /** Enabling and disabling are updates to `active`, and gated identically. */
    public function toggle(Mailbox $mailbox, Domain $domain): bool
    {
        return $this->isGlobalAdmin($mailbox);
    }

    private function isGlobalAdmin(Mailbox $mailbox): bool
    {
        return (bool) $mailbox->isglobaladmin;
    }
}
