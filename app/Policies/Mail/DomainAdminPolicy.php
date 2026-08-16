<?php

declare(strict_types=1);

namespace App\Policies\Mail;

use App\Actions\DomainAdmins\DemoteAdministratorAction;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Support\Authorization\Actor;

/**
 * `docs/features/domain-admins.md` BR-15: **every write in this feature is
 * global-admin only**, including assigning an administrator to a domain the
 * actor already administers. A domain admin sees the grants covering their own
 * domains and changes none of them.
 *
 * The reason is not tidiness. v1 offers no way to review or revoke a grant a
 * domain admin made, so authority that propagates itself has no path back.
 * Opening this later is additive; closing it later breaks a workflow
 * administrators have come to rely on.
 *
 * Setting or clearing `mailbox.isglobaladmin` is narrowed by BR-11 for a
 * different reason: it is not a domain-owned operation at all, so the scope
 * rule (`docs/policies/authorization.md` §2) — the only authorization concept
 * in the product — provides no domain under which a domain admin could be
 * authorized for it.
 *
 * The abilities that act on an administrator take a {@see Mailbox} as their
 * target, because that is the row whose flags change. They are reached as
 * `authorize('promoteToGlobal', [DomainAdmin::class, $mailbox])`.
 */
final class DomainAdminPolicy
{
    /**
     * Any administrator may open the listing. What it contains is decided in
     * the query, not here (`docs/policies/authorization.md` §3).
     */
    public function viewAny(Mailbox $actor): bool
    {
        return true;
    }

    /**
     * A grant is visible to whoever administers the domain it covers. The
     * sentinel is not a domain and `administers()` matches it for nobody, so a
     * global admin's `'ALL'` row is visible only through the global flag.
     */
    public function view(Mailbox $actor, DomainAdmin $grant): bool
    {
        return (new Actor($actor))->administers((string) $grant->domain);
    }

    public function create(Mailbox $actor): bool
    {
        return (bool) $actor->isglobaladmin;
    }

    public function delete(Mailbox $actor, DomainAdmin $grant): bool
    {
        return (bool) $actor->isglobaladmin;
    }

    /**
     * Grant or remove a single domain on an administrator. Global-admin only
     * even when the actor administers the domain in question (BR-15).
     */
    public function manage(Mailbox $actor, Mailbox $administrator): bool
    {
        return (bool) $actor->isglobaladmin;
    }

    public function promoteToGlobal(Mailbox $actor, Mailbox $administrator): bool
    {
        return (bool) $actor->isglobaladmin;
    }

    /**
     * **BR-A02** — an administrator cannot revoke their own global admin flag,
     * whether or not another global admin exists.
     *
     * It is authorization rather than a domain invariant: the refusal depends
     * on who is asking, not on the resulting state, and the correct answer is
     * a 403 recorded as an authorization failure. The invariant that depends
     * on the resulting state is BR-A01, enforced inside the transaction by
     * {@see DemoteAdministratorAction}.
     */
    public function revokeGlobal(Mailbox $actor, Mailbox $administrator): bool
    {
        if ($actor->getAuthIdentifier() === $administrator->getAuthIdentifier()) {
            return false;
        }

        return (bool) $actor->isglobaladmin;
    }

    /**
     * Full demotion — the `domain_admins` rows go and `isadmin` is cleared
     * (BR-16). It removes the actor's own global flag as a side effect when
     * they hold one, so BR-A02 applies here for the same reason.
     */
    public function demote(Mailbox $actor, Mailbox $administrator): bool
    {
        if ($actor->getAuthIdentifier() === $administrator->getAuthIdentifier()) {
            return false;
        }

        return (bool) $actor->isglobaladmin;
    }
}
