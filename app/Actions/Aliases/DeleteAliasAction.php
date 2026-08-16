<?php

declare(strict_types=1);

namespace App\Actions\Aliases;

use App\Models\Mail\Alias;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a standalone alias account and everything that names it.
 *
 * Three deletes, one transaction (`docs/features/aliases.md` BR-10, BR-14,
 * BR-18). There is no foreign key anywhere in `vmail`, so every dependant is
 * removed here or not at all, and an orphaned `forwardings` row is still acted
 * on by Postfix after the object it belonged to is gone.
 *
 * The **inbound** delete is the one that is easy to forget and expensive to
 * omit. Without it the alias address survives as a live routing target after
 * the alias is gone: another account still forwards to it, or a second alias
 * still lists it as a member, so the configuration looks right in every listing
 * and mail is routed to an address that no longer accepts it (BR-18). This
 * makes the alias cascade symmetric with the mailbox cascade, which already
 * removes inbound rows.
 *
 * Nothing else is written. There is no deletion record equivalent to
 * `deleted_mailboxes` — an alias owns no mail storage, so iRedMail's removal
 * cron has nothing to do for it — and no table in Mailward's own database is
 * cleaned, because none is keyed by an alias address (BR-14). The ordering rule
 * that puts Mailward-side rows before the `vmail` commit therefore has nothing
 * to order here; the audit entry is still written after the commit, never
 * before it.
 */
final class DeleteAliasAction
{
    public function handle(Alias $alias): void
    {
        $address = (string) $alias->getKey();
        $before = $alias->getAttributes();

        $removed = DB::connection('vmail')->transaction(function () use ($address): array {
            $vmail = DB::connection('vmail');

            /*
             * The members. Anchored on `address` and filtered on `is_list`, so
             * a `forwardings` row that carries the same address for one of the
             * other three purposes is left alone (BR-03, BR-10, AC-09).
             */
            $members = $vmail->table('forwardings')
                ->where('address', $address)
                ->where('is_list', 1)
                ->delete();

            /*
             * Everything that pointed *at* the alias: it as another account's
             * forwarding target, and it as a member of a second alias. Rows
             * carrying `is_maillist = 1` are not touched — mailing lists are
             * unmodelled in v1 (`docs/02-domain.md` §12) — and the accounts and
             * alias accounts those rows belonged to are themselves left intact
             * (BR-18).
             */
            $inbound = $vmail->table('forwardings')
                ->where('forwarding', $address)
                ->where('is_maillist', 0)
                ->delete();

            $vmail->table('alias')->where('address', $address)->delete();

            return ['members' => $members, 'inboundForwardings' => $inbound];
        });

        /*
         * One entry for the whole cascade, naming what it removed rather than
         * one entry per row. Recorded without a subject: the row a morph would
         * point at no longer exists, and the log deliberately keeps its
         * reference to the address afterwards (`docs/02-domain.md` §13, BR-14).
         */
        Audit::recordDeletion('alias', $address, $before + ['removed' => $removed]);
    }
}
