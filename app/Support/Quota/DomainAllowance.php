<?php

declare(strict_types=1);

namespace App\Support\Quota;

use Illuminate\Support\Facades\DB;

/**
 * What a domain still has room for.
 *
 * Both limits are counted **inside the transaction that performs the write**,
 * without locking the `domain` row (`docs/features/mailboxes.md` BR-29, decided
 * as Q16=A). Two simultaneous creates can therefore exceed a limit by one, and
 * the next create is refused. That is a guardrail rather than a guarantee, and
 * it is a decision — not a race nobody noticed.
 *
 * Quota is in **mebibytes**, and `maxquota` is an **aggregate pool** for the
 * domain rather than a per-mailbox ceiling: it bounds the sum of the quotas
 * already allocated (`docs/reference/observed-install.md` §4). Zero means
 * unlimited on both.
 */
final class DomainAllowance
{
    /**
     * Remaining mailbox slots, or null when the domain is unlimited.
     */
    public static function mailboxes(string $domain): ?int
    {
        $limit = (int) DB::connection('vmail')->table('domain')
            ->where('domain', $domain)->value('mailboxes');

        if ($limit <= 0) {
            return null;
        }

        $used = DB::connection('vmail')->table('mailbox')->where('domain', $domain)->count();

        return max(0, $limit - $used);
    }

    /**
     * Remaining quota pool in MiB, or null when the domain is unlimited.
     *
     * @param  string|null  $excluding  an address whose current quota does not count
     *                                  against the pool, for an edit rather than a create
     */
    public static function quota(string $domain, ?string $excluding = null): ?int
    {
        $limit = (int) DB::connection('vmail')->table('domain')
            ->where('domain', $domain)->value('maxquota');

        if ($limit <= 0) {
            return null;
        }

        $allocated = (int) DB::connection('vmail')->table('mailbox')
            ->where('domain', $domain)
            ->when($excluding !== null, fn ($query) => $query->where('username', '<>', $excluding))
            ->sum('quota');

        return max(0, $limit - $allocated);
    }
}
