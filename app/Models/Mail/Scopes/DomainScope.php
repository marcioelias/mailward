<?php

declare(strict_types=1);

namespace App\Models\Mail\Scopes;

use App\Models\Mail\MailModel;
use App\Support\Authorization\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every domain-owned mail record to the domains the acting
 * administrator may see.
 *
 * `docs/policies/authorization.md` §3 is explicit that this belongs in the
 * query rather than only in a Policy: a domain admin listing mailboxes must
 * never load every mailbox and filter afterwards, which is both slow and one
 * pagination bug away from leaking another domain's data.
 *
 * **With no authenticated actor this denies everything.** That is deliberate.
 * The alternative — treating "nobody is signed in" as "no restrictions" —
 * turns a forgotten route guard into a full disclosure. Deny by default is the
 * posture (`standards/security.md` §0), so the two legitimate unauthenticated
 * paths opt out explicitly and visibly at the call site, through
 * {@see MailModel::withoutDomainScope()}: authentication,
 * which runs before an actor exists, and console commands, which have no
 * request at all.
 *
 * @implements Scope<Model>
 */
final class DomainScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof MailModel) {
            return;
        }

        $column = $model->domainScopeColumn();

        if ($column === null) {
            return;
        }

        $actor = Actor::current();

        if ($actor->isGlobalAdmin()) {
            return;
        }

        if (! $actor->isAuthenticated()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $domains = $actor->administeredDomains();

        if ($domains === []) {
            /*
             * An administrator with no domains and no global flag is a valid
             * state — BR-A03 requires they can still sign in and see an empty
             * screen rather than an error.
             */
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->qualifyColumn($column), $domains);
    }
}
