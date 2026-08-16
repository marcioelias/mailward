<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Casts\NeverExpiresDate;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use App\Support\PasswordScheme\SchemeRegistry;
use App\Support\Storage\MaildirGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a mail account.
 *
 * **Never a single insert.** Every mailbox carries a row in `forwardings`
 * pointing at itself — `address = forwarding = the account's address`,
 * `is_forwarding = 1`. iRedMail's own account-creation script writes it, and a
 * mailbox without it appears correct in every listing and receives no mail
 * (`docs/02-domain.md` §5, `docs/features/mailboxes.md` BR-04). The two writes
 * are one transaction.
 */
final class CreateMailboxAction
{
    public function __construct(
        private readonly SchemeRegistry $schemes,
        private readonly MaildirGenerator $maildir,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated input, address already lower-cased
     */
    public function handle(array $attributes): Mailbox
    {
        $address = (string) $attributes['username'];
        $domain = Str::afterLast($address, '@');
        $plainPassword = (string) $attributes['password'];

        unset($attributes['password'], $attributes['password_confirmation']);

        $mailbox = DB::connection('vmail')->transaction(function () use ($attributes, $address, $domain, $plainPassword): Mailbox {
            $mailbox = new Mailbox($attributes);

            $mailbox->setAttribute('username', $address);
            $mailbox->setAttribute('domain', $domain);
            $mailbox->setAttribute('password', $this->schemes->hash($plainPassword));

            /*
             * Storage columns. The maildir is the relative tail; Dovecot
             * concatenates it with the other two and creates the directory
             * itself, so no privileged step is needed
             * (docs/reference/current-iredmail-behaviour.md §Q1).
             */
            $mailbox->setAttribute('storagebasedirectory', (string) config('mailward.maildir.base'));
            $mailbox->setAttribute('storagenode', (string) config('mailward.maildir.node'));
            $mailbox->setAttribute('maildir', $this->maildir->forAddress($address));

            // Written explicitly rather than left to the column default: the
            // sentinels differ per driver (BR-06, matrix D1/D2).
            $mailbox->setAttribute('created', now());
            $mailbox->setAttribute('modified', now());
            $mailbox->setAttribute('passwordlastchange', now());
            $mailbox->setAttribute('expired', NeverExpiresDate::SENTINEL);

            // NULL when unrestricted, never an empty string (BR-08).
            $mailbox->setAttribute('allow_nets', null);

            $mailbox->save();

            $this->writeSelfForwarding($address, $domain);

            return $mailbox;
        });

        Audit::record('created', $mailbox, after: $this->auditable($mailbox));

        return $mailbox;
    }

    /**
     * The invariant that breaks silently. Written inside the same transaction
     * as the mailbox, so an account can never exist without it.
     */
    private function writeSelfForwarding(string $address, string $domain): void
    {
        DB::connection('vmail')->table('forwardings')->insert([
            'address' => $address,
            'forwarding' => $address,
            'domain' => $domain,
            'dest_domain' => $domain,
            'is_forwarding' => 1,
            'is_alias' => 0,
            'is_list' => 0,
            'is_maillist' => 0,
            'active' => 1,
        ]);
    }

    /**
     * The password never reaches the audit log — it is stripped centrally as
     * well, but not putting it there in the first place is cheaper to reason
     * about.
     *
     * @return array<string, mixed>
     */
    private function auditable(Mailbox $mailbox): array
    {
        $attributes = $mailbox->getAttributes();

        unset($attributes['password']);

        return $attributes;
    }
}
