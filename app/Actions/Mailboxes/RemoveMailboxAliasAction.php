<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Forwarding;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes one per-user alias.
 *
 * A row this feature owns has two states, present and absent (BR-19). There is
 * no suspend: an alias is removed and created again, because nothing sourced
 * says any iRedMail component honours `forwardings.active` on these rows, and a
 * control that appears to suspend an address while mail keeps arriving is a
 * promise Mailward cannot keep.
 */
final class RemoveMailboxAliasAction
{
    public function handle(Mailbox $mailbox, Forwarding $alias): void
    {
        $owner = (string) $mailbox->getKey();
        $before = $alias->getAttributes();

        /*
         * The row must be an alias of *this* account. The controller already
         * resolves it that way (BR-10), so reaching this is either a console
         * caller or a defect — and the invariant belongs with the write rather
         * than only with the lookup.
         *
         * Flags are compared against the integer, never a PHP boolean: the
         * column is INT2 on PostgreSQL (BR-08, matrix D4). `getAttributes()`
         * is read rather than the cast property, so the comparison is against
         * what the database actually holds.
         */
        if ((int) $before['is_alias'] !== 1 || (string) $before['forwarding'] !== $owner) {
            throw ValidationException::withMessages([
                'address' => __('That alias does not belong to this account.'),
            ]);
        }

        DB::connection('vmail')->transaction(function () use ($alias): void {
            /*
             * Exactly this row, by its primary key. Every other row of the
             * account — its other aliases, its forwardings, and the
             * self-referencing row — is untouched (AC-12).
             */
            $alias->delete();
        });

        // After the vmail commit, never before (BR-12).
        Audit::recordDeletion(
            'mailbox_alias',
            (string) $before['address'].' → '.$owner,
            $before,
        );
    }
}
