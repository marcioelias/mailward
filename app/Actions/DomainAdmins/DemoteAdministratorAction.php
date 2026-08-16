<?php

declare(strict_types=1);

namespace App\Actions\DomainAdmins;

use App\Exceptions\LastGlobalAdmin;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use App\Support\Authorization\LastGlobalAdminGuard;
use Illuminate\Support\Facades\DB;

/**
 * Takes administrative authority away from an account.
 *
 * Two operations, sharing one guard:
 *
 * - {@see revokeGlobal()} clears `mailbox.isglobaladmin` and removes the
 *   `'ALL'` sentinel row — both representations of the same fact (BR-04).
 * - {@see handle()} is the full demotion: every `domain_admins` row goes and
 *   `isadmin` is cleared with them (BR-16), after which the account fails step 2
 *   of the login gate exactly as a mail user does.
 *
 * **Demotion is not a deletion** (BR-14). The `mailbox` row survives with its
 * `active` flag untouched, the account still receives mail, and nothing keyed
 * by its address in Mailward's own database is removed — not `panel_profiles`,
 * not `two_factor_secrets`. Those rows go only when the mailbox itself or its
 * domain is deleted, and they must still be there if the account is promoted
 * again, at which point the same second factor is still in force.
 */
final class DemoteAdministratorAction
{
    /**
     * @throws LastGlobalAdmin
     */
    public function revokeGlobal(Mailbox $administrator): void
    {
        $address = (string) $administrator->getKey();

        $before = [
            'isglobaladmin' => (bool) $administrator->isglobaladmin,
            'sentinelRow' => $this->hasSentinelRow($address),
        ];

        DB::connection('vmail')->transaction(function () use ($address): void {
            LastGlobalAdminGuard::assertNotTheLast($address);

            DB::connection('vmail')->table('mailbox')
                ->where('username', $address)
                ->update(['isglobaladmin' => 0, 'modified' => now()]);

            DB::connection('vmail')->table('domain_admins')
                ->where('username', $address)
                ->where('domain', DomainAdmin::ALL_DOMAINS)
                ->delete();
        });

        $administrator->setAttribute('isglobaladmin', false);

        Audit::record(
            'demoted',
            $administrator,
            before: $before,
            after: ['isglobaladmin' => false, 'sentinelRow' => false],
            description: 'global administrator flag revoked',
        );
    }

    /**
     * @throws LastGlobalAdmin
     */
    public function handle(Mailbox $administrator): void
    {
        $address = (string) $administrator->getKey();

        $before = [
            'isadmin' => (bool) $administrator->isadmin,
            'isglobaladmin' => (bool) $administrator->isglobaladmin,
        ];

        $removed = DB::connection('vmail')->transaction(function () use ($address): int {
            LastGlobalAdminGuard::assertNotTheLast($address);

            /*
             * BR-16: the flag goes with the grants. Leaving `isadmin` set would
             * produce an administrator who signs in and sees nothing, which is
             * a support ticket rather than a state — BR-A03's empty state
             * exists for the *domain was deleted* case and is not a demotion
             * target.
             */
            DB::connection('vmail')->table('mailbox')
                ->where('username', $address)
                ->update(['isadmin' => 0, 'isglobaladmin' => 0, 'modified' => now()]);

            // Every grant, the `'ALL'` sentinel included: the account is left
            // in the "not an administrator" state, which carries no rows at all
            // (docs/features/domain-admins.md, States).
            return DB::connection('vmail')->table('domain_admins')
                ->where('username', $address)
                ->delete();
        });

        $administrator->setAttribute('isadmin', false);
        $administrator->setAttribute('isglobaladmin', false);

        Audit::record(
            'demoted',
            $administrator,
            before: $before,
            after: ['isadmin' => false, 'isglobaladmin' => false, 'grantsRemoved' => $removed],
            description: 'demoted from administrator',
        );
    }

    private function hasSentinelRow(string $address): bool
    {
        return DB::connection('vmail')->table('domain_admins')
            ->where('username', $address)
            ->where('domain', DomainAdmin::ALL_DOMAINS)
            ->exists();
    }
}
