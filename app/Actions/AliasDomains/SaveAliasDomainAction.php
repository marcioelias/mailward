<?php

declare(strict_types=1);

namespace App\Actions\AliasDomains;

use App\Models\Mail\AliasDomain;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an alias domain.
 *
 * `alias_domain` has no `expired` column — neither schema file gives it one —
 * so unlike every other table here there is no sentinel to write
 * (`docs/features/alias-domains.md` BR-06, BR-08).
 *
 * The name itself is never updated: it is the primary key and renaming is out
 * of scope (BR-14). Only the target and `active` change.
 */
final class SaveAliasDomainAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AliasDomain
    {
        $aliasDomain = DB::connection('vmail')->transaction(function () use ($attributes): AliasDomain {
            $aliasDomain = new AliasDomain($attributes);

            $aliasDomain->setAttribute('created', now());
            $aliasDomain->setAttribute('modified', now());
            $aliasDomain->save();

            return $aliasDomain;
        });

        Audit::record('created', $aliasDomain, after: $aliasDomain->getAttributes());

        return $aliasDomain;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AliasDomain $aliasDomain, array $attributes): AliasDomain
    {
        $before = $aliasDomain->getOriginal();

        // The primary key is not editable here, whatever the caller passes.
        unset($attributes['alias_domain']);

        DB::connection('vmail')->transaction(function () use ($aliasDomain, $attributes): void {
            $aliasDomain->fill($attributes);
            $aliasDomain->setAttribute('modified', now());
            $aliasDomain->save();
        });

        Audit::record(
            'updated',
            $aliasDomain,
            before: array_intersect_key($before, $aliasDomain->getChanges()),
            after: $aliasDomain->getChanges(),
        );

        return $aliasDomain;
    }

    /**
     * Deleting an alias domain removes one row and nothing else. It owns no
     * accounts and no forwardings — the mail it accepts is delivered to the
     * target domain's own mailboxes (`docs/02-domain.md` §3).
     */
    public function delete(AliasDomain $aliasDomain): void
    {
        $name = (string) $aliasDomain->getKey();
        $before = $aliasDomain->getAttributes();

        DB::connection('vmail')->transaction(function () use ($name): void {
            DB::connection('vmail')->table('alias_domain')->where('alias_domain', $name)->delete();
        });

        Audit::recordDeletion('alias_domain', $name, $before);
    }
}
