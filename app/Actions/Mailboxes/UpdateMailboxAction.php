<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use App\Support\Authorization\LastGlobalAdminGuard;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Changes a mail account's profile, services, quota or password.
 *
 * The address is never changed: it is the primary key and is embedded in
 * `maildir`, in every `forwardings` row naming it, and in the directory
 * Dovecot has already created on disk. Renaming is out of scope.
 */
final class UpdateMailboxAction
{
    public function __construct(private readonly SchemeRegistry $schemes) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Mailbox $mailbox, array $attributes): Mailbox
    {
        $before = $mailbox->getOriginal();

        unset($attributes['username'], $attributes['domain'], $attributes['password']);

        DB::connection('vmail')->transaction(function () use ($mailbox, $attributes): void {
            /*
             * Deactivating is one of the three verbs BR-A01 names, alongside
             * demoting and deleting. Switching off the last global admin's
             * account locks the organisation out through a screen that never
             * mentions administrators.
             */
            if (array_key_exists('active', $attributes) && ! $attributes['active']) {
                LastGlobalAdminGuard::assertNotTheLast((string) $mailbox->getKey());
            }

            $mailbox->fill($attributes);
            $mailbox->setAttribute('modified', now());

            // NULL when unrestricted, never an empty string (BR-08).
            if ($mailbox->getAttribute('allow_nets') === '') {
                $mailbox->setAttribute('allow_nets', null);
            }

            $mailbox->save();
        });

        $changes = $mailbox->getChanges();

        if ($changes !== []) {
            Audit::record(
                'updated',
                $mailbox,
                before: array_intersect_key($before, $changes),
                after: $changes,
            );
        }

        return $mailbox;
    }

    /**
     * Writes a new mail password.
     *
     * **This is not a panel password.** The column authenticates IMAP, SMTP and
     * webmail, so the change takes effect everywhere at once and the interface
     * must say so (`docs/02-domain.md` §4, BR-10). The value is written in the
     * single generative scheme; every other scheme remains verifiable but is
     * never produced (BR-11).
     */
    public function setPassword(Mailbox $mailbox, string $plain): Mailbox
    {
        DB::connection('vmail')->transaction(function () use ($mailbox, $plain): void {
            $mailbox->setAttribute('password', $this->schemes->hash($plain));
            $mailbox->setAttribute('passwordlastchange', now());
            $mailbox->setAttribute('modified', now());
            $mailbox->save();
        });

        /*
         * The audit entry records that the password changed and never what it
         * changed to — not the plaintext, and not the hash either, which is a
         * credential in its own right.
         */
        Audit::record(
            'password-changed',
            $mailbox,
            after: ['passwordlastchange' => $mailbox->getAttribute('passwordlastchange')],
            description: 'password-changed',
        );

        return $mailbox;
    }
}
