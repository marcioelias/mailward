<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Forwarding;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a forwarding — a destination the account's mail is sent on to
 * (`docs/features/mailbox-aliases-forwardings.md` BR-04).
 *
 * The row is written as `address` = the owning mailbox, `forwarding` = the
 * destination, which is the same hand as the self-referencing row every
 * mailbox carries (`docs/02-domain.md` §5, CreateMailboxAction). OQ-A1 asks for
 * that to be confirmed against a real install as well; unlike the alias
 * direction it is at least implied by a row the installer itself writes.
 *
 * **No limit is counted before the insert**, and that absence is a decision
 * (BR-15): `domain.aliases` bounds standalone alias accounts in `vmail.alias`
 * only, so per-account forwardings are unbounded in v1.
 */
final class AddMailboxForwardingAction
{
    /**
     * @param  string  $target  the destination, already lower-cased at the request boundary (BR-03)
     */
    public function handle(Mailbox $mailbox, string $target): Forwarding
    {
        $owner = (string) $mailbox->getKey();

        $row = DB::connection('vmail')->transaction(function () use ($owner, $target): Forwarding {
            $row = new Forwarding([
                'address' => $owner,
                'forwarding' => $target,

                /*
                 * UNVERIFIED — OQ-A2, as in AddMailboxAliasAction. Filled the
                 * way CreateMailboxAction fills them on the self-referencing
                 * row: the domain of `address` and the domain of `forwarding`.
                 * For an external target that is the external domain, which is
                 * the reading OQ-A2 leaves open.
                 */
                'domain' => Str::afterLast($owner, '@'),
                'dest_domain' => Str::afterLast($target, '@'),

                /*
                 * Exactly one discriminator is set (BR-04), written as 1/0
                 * rather than true/false: the columns are INT2 on PostgreSQL,
                 * which has no implicit cast from boolean (BR-08, matrix D4).
                 */
                'is_forwarding' => 1,
                'is_alias' => 0,
                'is_list' => 0,
                'is_maillist' => 0,

                // Always 1 on create, and never updated afterwards: this
                // feature exposes no toggle (BR-19, Q19 answered option A).
                'active' => 1,
            ]);

            $row->save();

            return $row;
        });

        // After the vmail commit, never before: no transaction spans both
        // databases (BR-12, `docs/01-architecture.md` §3).
        Audit::record('created', $row, after: $row->getAttributes());

        return $row;
    }
}
