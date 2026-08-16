<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\LastGlobalAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Refuses any change that would leave the panel with no global administrator.
 *
 * BR-A01 (`docs/policies/authorization.md` §5) covers three verbs — demoted,
 * **deactivated** or **deleted** — and it lived inside the demotion action
 * only, which left the other two open: a global admin could deactivate or
 * delete the last global admin's mailbox and lock the organisation out through
 * a screen that never mentions administrators.
 *
 * It is a domain invariant rather than an authorization decision. The actor is
 * permitted; the resulting *state* is not. So it is asserted inside the
 * transaction that performs the change, with the rows locked, and the refusal
 * and the rollback are the same event. Checking before the transaction would
 * leave two administrators able to demote each other simultaneously and both
 * succeed.
 */
final class LastGlobalAdminGuard
{
    /**
     * @throws LastGlobalAdmin when this address is the only global admin left
     */
    public static function assertNotTheLast(string $address): void
    {
        $globalAdmins = DB::connection('vmail')->table('mailbox')

            // Integer bind, never a PHP boolean: PostgreSQL rejects a bool
            // against INT2 where MySQL accepts it (matrix D4).
            ->where('isglobaladmin', 1)
            ->lockForUpdate()
            ->pluck('username')
            ->map(strval(...))
            ->all();

        /*
         * An account that is not a global admin passes: changing it cannot
         * reduce the count, and refusing would block a legitimate change
         * without protecting anything.
         */
        if (! in_array($address, $globalAdmins, true)) {
            return;
        }

        if (count($globalAdmins) === 1) {
            throw LastGlobalAdmin::cannotBeDemoted($address);
        }
    }
}
