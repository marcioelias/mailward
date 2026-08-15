<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Models\Mail\Domain;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a domain and everything that belongs to it.
 *
 * An explicit cascade (`docs/features/domains.md` BR-16, decided as D1). There
 * are no foreign keys anywhere in `vmail`, so every dependant is removed here
 * or not at all — and an orphaned `forwardings` or `alias_domain` row is still
 * acted on by Postfix after the object it belonged to is gone.
 *
 * Ordering matters and is not incidental. No transaction can span both
 * databases (`docs/01-architecture.md` §3), so Mailward's own rows go first
 * and the `vmail` write commits last: a crash in between leaves mail data
 * intact and Mailward-side rows already gone, which is recoverable, rather
 * than the reverse.
 */
final class DeleteDomain
{
    public function __construct(private readonly RecordDeletedMailboxes $recordDeletedMailboxes) {}

    public function handle(Domain $domain): void
    {
        $name = (string) $domain->getKey();
        $before = $domain->getAttributes();

        $this->forgetMailwardRows($name);

        $removed = DB::connection('vmail')->transaction(function () use ($name): array {
            $vmail = DB::connection('vmail');

            /*
             * Written before the mailboxes disappear, because the row has to
             * carry the maildir of an account that is about to stop existing.
             * iRedMail's own cron reads this table and removes the files
             * (docs/02-domain.md §11).
             */
            $this->recordDeletedMailboxes->handle($name);

            $suffix = '%@'.$name;

            $counts = [
                'mailboxes' => $vmail->table('mailbox')->where('domain', $name)->count(),
                'aliases' => $vmail->table('alias')->where('domain', $name)->count(),
                'aliasDomains' => $vmail->table('alias_domain')->where('target_domain', $name)->count(),
            ];

            // Every forwarding whose address or target sits in this domain,
            // whichever of the four purposes the row serves.
            $vmail->table('forwardings')
                ->where('domain', $name)
                ->orWhere('dest_domain', $name)
                ->delete();

            $vmail->table('alias')->where('domain', $name)->delete();
            $vmail->table('alias_domain')->where('target_domain', $name)->delete();
            $vmail->table('alias_domain')->where('alias_domain', $name)->delete();

            /*
             * Grants held *by* the accounts of this domain, over any domain —
             * not only grants over this one. Without this, deleting a domain
             * and re-creating an administrator at the same address silently
             * regains every domain the old account administered. That is a
             * privilege escalation, not untidiness
             * (docs/reference/decisions-needed.md Q6, answered 2026-08-15).
             */
            $counts['grantsHeldByItsAccounts'] = $vmail->table('domain_admins')
                ->where('username', 'like', $suffix)
                ->delete();

            $vmail->table('mailbox')->where('domain', $name)->delete();

            // Grants over this domain, held by anyone. BR-13, and BR-A03.
            $vmail->table('domain_admins')->where('domain', $name)->delete();

            $vmail->table('domain')->where('domain', $name)->delete();

            return $counts;
        });

        /*
         * One entry for the whole cascade, naming what it removed rather than
         * one entry per row (Q9). Recorded without a subject: the row a morph
         * would point at no longer exists, and the log deliberately keeps
         * references to things that are gone (docs/02-domain.md §13).
         */
        Audit::recordDeletion('domain', $name, $before + ['removed' => $removed]);
    }

    /**
     * Mailward's own rows keyed by an address of this domain. `audit_log` is
     * deliberately excluded: it is append-only and keeps references to
     * accounts that no longer exist (`docs/02-domain.md` §13, BR-17).
     */
    private function forgetMailwardRows(string $domain): void
    {
        $suffix = '@'.$domain;

        foreach (['panel_profiles', 'two_factor_secrets'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->where('address', 'like', '%'.$suffix)->delete();
            }
        }
    }
}
