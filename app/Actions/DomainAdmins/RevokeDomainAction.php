<?php

declare(strict_types=1);

namespace App\Actions\DomainAdmins;

use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Removes one administrator's grant over one domain.
 *
 * It removes that row and nothing else (AC-11). In particular it never touches
 * the `'ALL'` sentinel — revoking a global admin's authority is a different
 * operation with a different rule behind it (BR-04, BR-A01), reached through
 * {@see DemoteAdministratorAction}.
 *
 * `mailbox.isadmin` is deliberately left alone. An administrator with no
 * domains and no global flag is a valid state: they sign in and see an empty
 * screen rather than an error (BR-A03). Only a full demotion clears the flag
 * (BR-16).
 */
final class RevokeDomainAction
{
    public function handle(Mailbox $administrator, string $domain): void
    {
        $address = (string) $administrator->getKey();

        if ($domain === DomainAdmin::ALL_DOMAINS) {
            return;
        }

        $removed = DB::connection('vmail')->transaction(
            fn (): int => DB::connection('vmail')->table('domain_admins')
                ->where('username', $address)
                ->where('domain', $domain)
                ->delete()
        );

        if ($removed === 0) {
            return;
        }

        Audit::record(
            'revoked',
            $administrator,
            before: ['domain' => $domain],
            description: 'administrator revoked from domain',
        );
    }
}
