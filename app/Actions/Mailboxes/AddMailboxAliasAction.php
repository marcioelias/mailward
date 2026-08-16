<?php

declare(strict_types=1);

namespace App\Actions\Mailboxes;

use App\Models\Mail\Forwarding;
use App\Models\Mail\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a per-user alias — an extra address delivering into an existing account
 * (`docs/features/mailbox-aliases-forwardings.md` BR-04).
 *
 * ---------------------------------------------------------------------------
 * **UNVERIFIED — OQ-A1. Which column holds which address.**
 *
 * The row is written as `address` = the alias, `forwarding` = the owning
 * mailbox. `docs/02-domain.md` §5 names the concept without stating the
 * direction, and OQ-A1 says it must be read off a real install. Nothing here
 * has been read off one.
 *
 * It is not a guess either. The feature document decides it twice, in passing:
 * BR-18 makes a local forwarding target exist when there is "a `forwardings`
 * row whose `address` is the target with `is_alias = 1` — a per-account alias
 * written by this feature", which is only true if `address` holds the alias;
 * and AC-22 spells a per-account alias out as `address = 'sales@a.com'`,
 * `is_alias = 1`, for a mailbox that is not `sales@a.com`.
 *
 * Note this is the *opposite* hand from a forwarding row and from the
 * self-referencing row, where `address` is the account's own address. That
 * asymmetry is what OQ-A1 exists to confirm, and getting it backwards produces
 * rows that look right and route mail the wrong way. Confirm against an
 * install before this feature leaves draft.
 * ---------------------------------------------------------------------------
 *
 * **No limit is counted before the insert**, and that absence is a decision
 * (BR-15): `domain.aliases` bounds standalone alias accounts in `vmail.alias`
 * only, so per-account aliases are unbounded in v1.
 */
final class AddMailboxAliasAction
{
    /**
     * @param  string  $alias  the alias address, already lower-cased at the request boundary (BR-03)
     */
    public function handle(Mailbox $mailbox, string $alias): Forwarding
    {
        $owner = (string) $mailbox->getKey();

        $row = DB::connection('vmail')->transaction(function () use ($alias, $owner): Forwarding {
            $row = new Forwarding([
                'address' => $alias,
                'forwarding' => $owner,

                /*
                 * UNVERIFIED — OQ-A2. `docs/02-domain.md` §5 lists both columns
                 * without semantics. They are filled the way
                 * CreateMailboxAction fills them on the self-referencing row:
                 * the domain of `address` and the domain of `forwarding`. Both
                 * are NOT NULL with an `''` default on both drivers, so they
                 * are written explicitly rather than left to the default.
                 */
                'domain' => Str::afterLast($alias, '@'),
                'dest_domain' => Str::afterLast($owner, '@'),

                /*
                 * Exactly one discriminator is set, and `is_list` /
                 * `is_maillist` stay at 0 — those two rows belong to standalone
                 * alias accounts and to mlmmj lists, neither of which this
                 * feature touches (BR-04, BR-05).
                 *
                 * Written as 1/0 rather than true/false: the columns are INT2
                 * on PostgreSQL, which has no implicit cast from boolean
                 * (BR-08, matrix D4).
                 */
                'is_alias' => 1,
                'is_forwarding' => 0,
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
