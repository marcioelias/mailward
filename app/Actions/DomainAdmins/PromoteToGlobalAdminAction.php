<?php

declare(strict_types=1);

namespace App\Actions\DomainAdmins;

use App\Casts\NeverExpiresDate;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Promotes an existing mail account to global admin.
 *
 * **A global admin is represented twice** (BR-04): the `mailbox.isglobaladmin`
 * flag, which is the authoritative input to every authorization decision
 * (BR-03), and a `domain_admins` row carrying the
 * {@see DomainAdmin::ALL_DOMAINS} sentinel. Both are written here, in one
 * transaction, so the pair cannot drift because of anything Mailward did.
 *
 * That two-write representation is **sourced from iRedMail's own documentation
 * and has not been verified against a running install** — OQ-DA-09, and
 * `docs/reference/open-questions-research.md` OQ-02. If the verification
 * refutes it, the sentinel row disappears from this action and BR-05's
 * exclusion goes with it.
 *
 * It only ever grants, so BR-A01 and BR-A02 do not constrain it: no promotion
 * can reduce the number of global admins. It is also the repair for a drifted
 * pair, which is why it is idempotent (BR-19, AC-05) — re-running it on an
 * account that is already a global admin succeeds and raises no duplicate-key
 * error, which is the failure iRedMail's own documented SQL produces.
 */
final class PromoteToGlobalAdminAction
{
    public function handle(Mailbox $administrator): void
    {
        $address = (string) $administrator->getKey();

        $before = [
            'isadmin' => (bool) $administrator->isadmin,
            'isglobaladmin' => (bool) $administrator->isglobaladmin,
            'sentinelRow' => $this->hasSentinelRow($address),
        ];

        DB::connection('vmail')->transaction(function () use ($address): void {
            // Integer binds, never PHP booleans: TINYINT(1) on MySQL, INT2 on
            // PostgreSQL (docs/reference/schema-type-matrix.md, D4).
            DB::connection('vmail')->table('mailbox')
                ->where('username', $address)
                ->update(['isadmin' => 1, 'isglobaladmin' => 1, 'modified' => now()]);

            $this->writeSentinelRow($address);
        });

        $administrator->setAttribute('isadmin', true);
        $administrator->setAttribute('isglobaladmin', true);

        Audit::record(
            'promoted',
            $administrator,
            before: $before,
            after: ['isadmin' => true, 'isglobaladmin' => true, 'sentinelRow' => true],
            description: 'promoted to global administrator',
        );
    }

    public function hasSentinelRow(string $address): bool
    {
        return DB::connection('vmail')->table('domain_admins')
            ->where('username', $address)
            ->where('domain', DomainAdmin::ALL_DOMAINS)
            ->exists();
    }

    /**
     * Idempotent by BR-02: `(username, domain)` is the composite primary key,
     * so the row is refreshed rather than inserted a second time. Every
     * timestamp is written explicitly, with the never-expires sentinel, and the
     * column default is never allowed to fire (BR-09, BR-10).
     */
    private function writeSentinelRow(string $address): void
    {
        if ($this->hasSentinelRow($address)) {
            DB::connection('vmail')->table('domain_admins')
                ->where('username', $address)
                ->where('domain', DomainAdmin::ALL_DOMAINS)
                ->update([
                    'modified' => now(),
                    'expired' => NeverExpiresDate::SENTINEL,
                    'active' => 1,
                ]);

            return;
        }

        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => $address,
            'domain' => DomainAdmin::ALL_DOMAINS,
            'created' => now(),
            'modified' => now(),
            'expired' => NeverExpiresDate::SENTINEL,
            'active' => 1,
        ]);
    }
}
