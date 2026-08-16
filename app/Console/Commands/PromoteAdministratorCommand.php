<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DomainAdmins\PromoteToGlobalAdminAction;
use App\Models\AuditEntry;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use Illuminate\Console\Command;

/**
 * The escape hatch — BR-A04, `docs/policies/authorization.md` §5.
 *
 * It exists so that a lockout caused by the web interface can be repaired
 * without the web interface. No panel permission gates it: anyone with shell
 * access to the server can run it, by design, because shell access to the mail
 * server is already sufficient to edit `vmail` directly.
 *
 * **`--global` is mandatory and there is no per-domain form** (BR-17). Invoked
 * without it the command refuses and writes nothing; it does not fall back to a
 * per-domain grant, because no such form exists. Per-domain grants are made
 * through `POST /admins/{address}/domains` and nowhere else. The command is
 * trustworthy in its role precisely because it does one thing — a
 * general-purpose grant tool reachable by anyone with shell access is a larger
 * surface than the escape hatch requires.
 *
 * It only ever grants, so BR-A01 and BR-A02 do not constrain it.
 *
 * Attribution is not handled here. A write with no authenticated actor is
 * stamped by {@see AuditEntry} with
 * {@see AuditEntry::CONSOLE_ACTOR} and with the invoking OS user
 * and host in place of the IP (BR-18) — recording the promoted address as the
 * actor would make the log claim the account promoted itself.
 */
final class PromoteAdministratorCommand extends Command
{
    /**
     * `--global` cannot be declared required: Symfony has no such thing for a
     * value-less option. It is enforced as the first validation step instead,
     * which is also where the contract puts it.
     */
    protected $signature = 'mailward:promote
        {address : The mail account to promote}
        {--global : Promote to global admin. Mandatory — there is no per-domain form}';

    protected $description = 'Promote an existing mail account to global administrator (recovery command)';

    public function handle(PromoteToGlobalAdminAction $promoteToGlobalAdmin): int
    {
        if (! $this->option('global')) {
            $this->components->error('--global is required. There is no per-domain form of this command; use the panel to assign domains.');

            return self::FAILURE;
        }

        // BR-08: trimmed and lower-cased before use, on look-ups as well as
        // writes. Dovecot only ever looks a lower-cased address up.
        $address = mb_strtolower(trim((string) $this->argument('address')));

        if (! $this->isStorableAddress($address)) {
            $this->components->error("'{$address}' is not a mail address that can be stored in domain_admins.");

            return self::FAILURE;
        }

        /*
         * Resolved without the domain scope, which denies everything when there
         * is no authenticated actor — the deny-by-default posture that a
         * console run opts out of explicitly and visibly
         * ({@see \App\Models\Mail\MailModel::scopeWithoutDomainScope()}).
         *
         * It deliberately does not require the account to be active, unexpired
         * or already an administrator: a recovery tool that refuses to run on a
         * broken install is not a recovery tool.
         */
        $administrator = Mailbox::withoutDomainScope()->whereKey($address)->first();

        if (! $administrator instanceof Mailbox) {
            $this->components->error("No mail account exists at {$address}. This command promotes an existing mailbox; it does not create one.");

            return self::FAILURE;
        }

        $before = [
            'isadmin' => (bool) $administrator->isadmin,
            'isglobaladmin' => (bool) $administrator->isglobaladmin,
            'allRow' => $promoteToGlobalAdmin->hasSentinelRow($address),
        ];

        $promoteToGlobalAdmin->handle($administrator);

        $this->report($address, $before);

        return self::SUCCESS;
    }

    /**
     * BR-07: both key columns of `domain_admins` are `CHARACTER SET ascii` on
     * MySQL, so an internationalised address cannot be stored there at all. It
     * is refused rather than written and silently truncated
     * (docs/reference/schema-type-matrix.md, D8).
     */
    private function isStorableAddress(string $address): bool
    {
        return preg_match(
            '/^[a-z0-9._%+-]+@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
            $address,
        ) === 1;
    }

    /**
     * The before and after values of both flags and of the sentinel row, so a
     * no-op re-run is visibly a no-op rather than an unexplained success.
     *
     * @param  array{isadmin: bool, isglobaladmin: bool, allRow: bool}  $before
     */
    private function report(string $address, array $before): void
    {
        $this->line("Address: {$address}");

        $this->table(
            ['', 'Before', 'After'],
            [
                ['mailbox.isadmin', $this->yesNo($before['isadmin']), $this->yesNo(true)],
                ['mailbox.isglobaladmin', $this->yesNo($before['isglobaladmin']), $this->yesNo(true)],
                ["domain_admins '".DomainAdmin::ALL_DOMAINS."' row", $this->yesNo($before['allRow']), $this->yesNo(true)],
            ],
        );

        $this->components->info(
            $before['isglobaladmin'] && $before['allRow']
                ? "{$address} was already a global administrator. Nothing changed."
                : "{$address} is now a global administrator."
        );
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
