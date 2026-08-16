<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use App\Support\Authorization\LastGlobalAdminGuard;
use App\Support\Storage\MaildirGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a mail account and everything that points at it.
 *
 * Mailward writes a row into `deleted_mailboxes` and iRedMail's own cron
 * removes the files. No root, no shell, no privileged helper
 * (`docs/01-architecture.md` §6).
 *
 * Ordering is deliberate: Mailward's own rows go first and the `vmail` writes
 * commit last, because no transaction spans both databases (BR-21, BR-25). A
 * crash between them leaves mail data intact and Mailward-side rows already
 * gone, which is recoverable; the reverse is not.
 */
final class DeleteMailboxAction
{
    public function handle(Mailbox $mailbox): void
    {
        $address = (string) $mailbox->getKey();
        $domain = (string) $mailbox->domain;
        $before = $this->auditable($mailbox);

        $this->forgetMailwardRows($address);

        $removed = DB::connection('vmail')->transaction(function () use ($mailbox, $address, $domain): array {
            $vmail = DB::connection('vmail');

            /*
             * BR-A01 names three verbs — demoted, deactivated or deleted — and
             * this is one of them. Asserted inside the transaction with the
             * rows locked, so the refusal and the rollback are one event.
             */
            LastGlobalAdminGuard::assertNotTheLast($address);

            $this->recordDeletion($mailbox, $address, $domain);

            /*
             * Every forwarding row naming this address, in either direction:
             * its own self-forwarding row, its per-user aliases, its
             * forwardings, and rows elsewhere naming it as a destination or as
             * a member of an alias. Leaving the inbound ones would keep the
             * address alive as a routing target after the account is gone
             * (BR-23, and Q6).
             */
            $outbound = $vmail->table('forwardings')->where('address', $address)->delete();
            $inbound = $vmail->table('forwardings')->where('forwarding', $address)->delete();

            /*
             * Grants held by this account over any domain. Without this,
             * re-creating the same address silently regains every domain the
             * old account administered — a privilege escalation, not
             * untidiness (BR-27).
             */
            $grants = $vmail->table('domain_admins')->where('username', $address)->delete();

            $vmail->table('mailbox')->where('username', $address)->delete();

            return [
                'forwardingsFrom' => $outbound,
                'forwardingsTo' => $inbound,
                'domainAdminGrants' => $grants,
            ];
        });

        Audit::recordDeletion('mailbox', $address, $before + ['removed' => $removed]);
    }

    /**
     * The path here is **absolute** — the concatenation of the three storage
     * columns. `mailbox.maildir` alone is the relative tail, and writing it
     * would leave iRedMail's cron resolving a path that does not exist, so the
     * files would outlive the account in silence (BR-24).
     */
    private function recordDeletion(Mailbox $mailbox, string $address, string $domain): void
    {
        $now = now();

        DB::connection('vmail')->table('deleted_mailboxes')->insert([
            'timestamp' => $now,
            'username' => $address,
            'domain' => $domain,
            'maildir' => MaildirGenerator::absolutePath(
                (string) $mailbox->storagebasedirectory,
                (string) $mailbox->storagenode,
                (string) $mailbox->maildir,
            ),
            'bytes' => 0,
            'messages' => 0,
            'admin' => Auth::user()?->getAuthIdentifier() ?? 'console',
            'delete_date' => $now->toDateString(),
        ]);
    }

    /**
     * `audit_log` is deliberately excluded: it keeps references to accounts
     * that no longer exist, and it is the one table exempt from this cleanup
     * (`docs/02-domain.md` §13, BR-18).
     */
    private function forgetMailwardRows(string $address): void
    {
        foreach (['panel_profiles', 'two_factor_secrets'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->where('address', $address)->delete();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Mailbox $mailbox): array
    {
        $attributes = $mailbox->getAttributes();

        unset($attributes['password']);

        return $attributes;
    }
}
