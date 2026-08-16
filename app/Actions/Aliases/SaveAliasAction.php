<?php

declare(strict_types=1);

namespace App\Actions\Aliases;

use App\Casts\NeverExpiresDate;
use App\Models\Mail\Alias;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a standalone alias account, and adds or removes one of its
 * members.
 *
 * The alias account and its members live in **two different tables**, and that
 * split is the feature (`docs/features/aliases.md` BR-01, BR-02). The account
 * is a row in `alias`; a member is a row in `forwardings` with `is_list = 1`,
 * `address` = the alias and `forwarding` = the destination. Nothing here ever
 * writes a member into `alias`, and nothing here reads, modifies or deletes a
 * `forwardings` row carrying one of the other three flags (BR-03).
 *
 * **Creating an alias is a single-table write.** A member-less alias is a valid
 * state, flagged in the interface rather than prevented (BR-20), so unlike a
 * mailbox there is no second row that must exist for the account to work.
 *
 * The address is never updated: it is the primary key and it is also the value
 * every member row's `address` carries, so renaming is out of scope.
 */
final class SaveAliasAction
{
    /**
     * @param  array<string, mixed>  $attributes  validated input, address already lower-cased
     */
    public function create(array $attributes): Alias
    {
        $address = (string) $attributes['address'];
        $domain = Str::afterLast($address, '@');

        $alias = DB::connection('vmail')->transaction(function () use ($attributes, $address, $domain): Alias {
            $alias = new Alias($attributes);

            $alias->setAttribute('address', $address);

            // The scope key, and it is the address's own domain — which the
            // request has already proved exists in `domain` (BR-12, BR-16).
            $alias->setAttribute('domain', $domain);

            /*
             * Written explicitly rather than left to the column default: the
             * two schema files ship different defaults and different
             * never-expires sentinels (BR-08, matrix D1/D2). Expiry itself is
             * always tested as `expired > now()`, never by equality against
             * the constant written here.
             */
            $alias->setAttribute('created', now());
            $alias->setAttribute('modified', now());
            $alias->setAttribute('expired', NeverExpiresDate::SENTINEL);

            // Both columns are NOT NULL with an empty-string default in both
            // schemas; `accesspolicy` stays free text until OQ-AL-01 is
            // answered, and Mailward validates it against no list (BR-11).
            $alias->setAttribute('name', (string) ($attributes['name'] ?? ''));
            $alias->setAttribute('accesspolicy', (string) ($attributes['accesspolicy'] ?? ''));

            $alias->save();

            return $alias;
        });

        Audit::record('created', $alias, after: $alias->getAttributes());

        return $alias;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Alias $alias, array $attributes): Alias
    {
        $before = $alias->getOriginal();

        // Neither the primary key nor the scope key derived from it is
        // editable here, whatever the caller passes.
        unset($attributes['address'], $attributes['domain']);

        /*
         * `name` and `accesspolicy` are NOT NULL in both schemas, with an
         * empty-string default. An emptied field arrives here as null — the
         * framework converts an empty string on the way in and the rules
         * accept it — and writing that null would be a driver error rather
         * than the erasure the administrator asked for.
         */
        foreach (['name', 'accesspolicy'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = (string) ($attributes[$column] ?? '');
            }
        }

        DB::connection('vmail')->transaction(function () use ($alias, $attributes): void {
            $alias->fill($attributes);
            $alias->setAttribute('modified', now());
            $alias->save();
        });

        Audit::record(
            'updated',
            $alias,
            before: array_intersect_key($before, $alias->getChanges()),
            after: $alias->getChanges(),
        );

        return $alias;
    }

    /**
     * Adds one member row.
     *
     * `active` is written as the integer `1` and is never updated afterwards:
     * nothing sourced says any iRedMail component reads it on an `is_list` row,
     * and a toggle that appears to suspend a member while mail keeps being
     * delivered to them is a promise Mailward cannot keep (BR-21). The three
     * other flags are written as `0` explicitly, so the row can only ever be
     * read as a member of a standalone alias (BR-03).
     */
    public function addMember(Alias $alias, string $member): void
    {
        $address = (string) $alias->getKey();

        DB::connection('vmail')->transaction(function () use ($address, $member): void {
            DB::connection('vmail')->table('forwardings')->insert([
                'address' => $address,
                'forwarding' => $member,

                /*
                 * How these two are populated is undecided in `docs/`
                 * (OQ-AL-02). The domain part of each side is what iRedMail's
                 * own rows carry and what CreateMailboxAction already writes,
                 * and nothing in Mailward reads them: the scope key is
                 * `alias.domain` and member queries are anchored on
                 * `forwardings.address` (BR-05, BR-12).
                 */
                'domain' => Str::afterLast($address, '@'),
                'dest_domain' => Str::afterLast($member, '@'),

                'is_forwarding' => 0,
                'is_alias' => 0,
                'is_list' => 1,
                'is_maillist' => 0,
                'active' => 1,
            ]);
        });

        Audit::record('member-added', $alias, after: ['address' => $address, 'forwarding' => $member]);
    }

    /**
     * Removes one member row and only that one.
     *
     * Filtered on `is_list` as well as on the pair, so the mandatory
     * self-referencing row of a mailbox that happens to share the address, and
     * any per-account alias or forwarding, survive untouched (BR-03, AC-08).
     * Removing the last member is permitted: an alias whose members are being
     * replaced passes through the empty state legitimately (BR-20).
     */
    public function removeMember(Alias $alias, string $member): void
    {
        $address = (string) $alias->getKey();

        $removed = DB::connection('vmail')->transaction(fn (): int => DB::connection('vmail')
            ->table('forwardings')
            ->where('address', $address)
            ->where('forwarding', $member)
            ->where('is_list', 1)
            ->delete());

        Audit::record('member-removed', $alias, before: [
            'address' => $address,
            'forwarding' => $member,
            'removed' => $removed,
        ]);
    }
}
