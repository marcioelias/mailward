<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Forwarding;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes one forwarding.
 *
 * **The self-referencing row is refused here, not only hidden from the
 * listing.** Every mailbox carries a row with
 * `address = forwarding =` its own address and `is_forwarding = 1`; iRedMail's
 * own account-creation script writes it, and an account without it appears
 * correct in every listing and receives no mail (`docs/02-domain.md` §5,
 * BR-06). Hiding it from the interface is not enough — the refusal is the
 * invariant, and it has to hold for a crafted request and for a console caller
 * too.
 *
 * A row this feature owns has two states, present and absent (BR-19): there is
 * no suspend, because nothing sourced says any iRedMail component honours
 * `forwardings.active` on these rows, and a control that appears to stop mail
 * being copied while it keeps arriving is worse than no control.
 */
final class RemoveMailboxForwardingAction
{
    public function handle(Mailbox $mailbox, Forwarding $forwarding): void
    {
        $owner = (string) $mailbox->getKey();
        $before = $forwarding->getAttributes();

        /*
         * Flags are compared against the integer, never a PHP boolean: the
         * column is INT2 on PostgreSQL (BR-08, matrix D4). `getAttributes()`
         * is read rather than the cast property, so the comparison is against
         * what the database actually holds.
         */
        if ((int) $before['is_forwarding'] !== 1 || (string) $before['address'] !== $owner) {
            throw ValidationException::withMessages([
                'forwarding' => __('That forwarding does not belong to this account.'),
            ]);
        }

        // BR-06. Removing it stops the account receiving mail at all.
        if ((string) $before['address'] === (string) $before['forwarding']) {
            throw ValidationException::withMessages([
                'forwarding' => __('This row is what makes the account receive its own mail. It cannot be removed.'),
            ]);
        }

        DB::connection('vmail')->transaction(function () use ($forwarding): void {
            /*
             * Exactly this row, by its primary key. Every other row of the
             * account — its aliases, its other forwardings, and the
             * self-referencing row — is untouched (AC-12).
             */
            $forwarding->delete();
        });

        // After the vmail commit, never before (BR-12).
        Audit::recordDeletion(
            'mailbox_forwarding',
            $owner.' → '.(string) $before['forwarding'],
            $before,
        );
    }
}
