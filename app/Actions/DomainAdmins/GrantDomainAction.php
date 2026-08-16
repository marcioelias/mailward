<?php

declare(strict_types=1);

namespace App\Actions\DomainAdmins;

use App\Casts\NeverExpiresDate;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use App\Support\Authorization\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Makes an existing mail account an administrator of zero or more domains.
 *
 * It never creates an account: promotion targets a `mailbox` row that already
 * exists and the legacy `admin` table is neither read nor written (BR-01).
 *
 * `mailbox.isadmin` is written in the same transaction as the grants, and not
 * only when domains are given. A `domain_admins` row on an account with
 * `isadmin = 0` confers nothing at all — the account fails step 2 of the login
 * gate (`docs/policies/authorization.md` §6) and never reaches the panel to use
 * the grant. Demotion is the mirror image of this and clears the same flag
 * (BR-16).
 */
final class GrantDomainAction
{
    /**
     * @param  list<string>  $domains  Real domain names, already lower-cased at
     *                                 the request boundary (BR-08). The `'ALL'`
     *                                 sentinel is not a domain and is never
     *                                 accepted here; it belongs to
     *                                 {@see PromoteToGlobalAdminAction}.
     */
    public function handle(Mailbox $administrator, array $domains = []): void
    {
        $address = (string) $administrator->getKey();
        $wasAdmin = (bool) $administrator->isadmin;

        $domains = array_values(array_filter(
            array_unique($domains),
            fn (string $domain): bool => $domain !== '' && $domain !== DomainAdmin::ALL_DOMAINS,
        ));

        $granted = DB::connection('vmail')->transaction(function () use ($address, $domains): array {
            DB::connection('vmail')->table('mailbox')
                ->where('username', $address)

                // Bound as an integer, never a PHP boolean: the column is
                // TINYINT(1) on MySQL and INT2 on PostgreSQL, and PDO's
                // PostgreSQL driver binds a bool as 't'
                // (docs/reference/schema-type-matrix.md, D4).
                ->update(['isadmin' => 1, 'modified' => now()]);

            $granted = [];

            foreach ($domains as $domain) {
                if ($this->grant($address, $domain)) {
                    $granted[] = $domain;
                }
            }

            return $granted;
        });

        $administrator->setAttribute('isadmin', true);

        Audit::record(
            'granted',
            $administrator,
            before: ['isadmin' => $wasAdmin],
            after: ['isadmin' => true, 'domains' => $granted],
            description: 'administrator granted domains',
        );
    }

    /**
     * One grant, idempotent by BR-02: `(username, domain)` is the composite
     * primary key, so assigning the same domain twice is a no-op rather than a
     * duplicate-key error.
     *
     * Re-granting an existing row also revives it. A row that is inactive or
     * past its `expired` date confers nothing (BR-10, and
     * {@see Actor::administeredDomains()}), so
     * leaving a dead row untouched would make the grant silently fail.
     *
     * @return bool Whether the grant was newly created.
     */
    private function grant(string $address, string $domain): bool
    {
        $existing = DB::connection('vmail')->table('domain_admins')
            ->where('username', $address)
            ->where('domain', $domain)
            ->exists();

        if ($existing) {
            DB::connection('vmail')->table('domain_admins')
                ->where('username', $address)
                ->where('domain', $domain)
                ->update([
                    'modified' => now(),
                    'expired' => NeverExpiresDate::SENTINEL,
                    'active' => 1,
                ]);

            return false;
        }

        /*
         * Every timestamp is written explicitly and the column default is
         * never allowed to fire (BR-09). `expired` carries the canonical
         * never-expires sentinel rather than NULL, because the two schema
         * files ship different far-future values and neither column is
         * nullable (D1, D2).
         */
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => $address,
            'domain' => $domain,
            'created' => now(),
            'modified' => now(),
            'expired' => NeverExpiresDate::SENTINEL,
            'active' => 1,
        ]);

        return true;
    }
}
