<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes the `deleted_mailboxes` rows that make iRedMail's own cron remove the
 * mail files of accounts Mailward is about to delete.
 *
 * This is how Mailward deletes mail storage: it writes a row, and iRedMail's
 * existing machinery does the privileged work. No root, no shell, no helper
 * (`docs/01-architecture.md` §6).
 *
 * **The path is a concatenation, and that is not cosmetic.**
 * `deleted_mailboxes.maildir` is documented as absolute, while
 * `mailbox.maildir` is the relative tail below `storagebasedirectory` and
 * `storagenode` — Dovecot's own `user_query` joins the three. Writing the
 * relative value alone would leave the cron resolving a path that does not
 * exist, so the files would survive the account silently. The derivation is
 * sourced but **not yet confirmed against a running install**
 * (`docs/features/mailboxes.md` OQ-M3).
 */
final class RecordDeletedMailboxesAction
{
    public function handle(string $domain): void
    {
        $vmail = DB::connection('vmail');

        $mailboxes = $vmail->table('mailbox')
            ->where('domain', $domain)
            ->get(['username', 'storagebasedirectory', 'storagenode', 'maildir']);

        if ($mailboxes->isEmpty()) {
            return;
        }

        $actor = Auth::user()?->getAuthIdentifier() ?? 'console';
        $now = now();

        $rows = $mailboxes->map(fn (object $mailbox): array => [
            'timestamp' => $now,
            'username' => $mailbox->username,
            'domain' => $domain,
            'maildir' => $this->absoluteMaildir($mailbox),
            'bytes' => 0,
            'messages' => 0,
            'admin' => $actor,
            'delete_date' => $now->toDateString(),
        ])->all();

        $vmail->table('deleted_mailboxes')->insert($rows);
    }

    private function absoluteMaildir(object $mailbox): string
    {
        $parts = array_filter([
            trim((string) $mailbox->storagebasedirectory, '/'),
            trim((string) $mailbox->storagenode, '/'),
            trim((string) $mailbox->maildir, '/'),
        ], fn (string $part): bool => $part !== '');

        return '/'.implode('/', $parts);
    }
}
