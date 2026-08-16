<?php

declare(strict_types=1);

namespace App\Http\Requests\Mailboxes;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Mailbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A forwarding — a destination the account's mail is sent on to
 * (`docs/features/mailbox-aliases-forwardings.md`, `is_forwarding = 1`).
 *
 * **No limit is checked here, and that is a decision rather than an omission**
 * (BR-15). `domain.aliases` bounds standalone alias accounts in `vmail.alias`
 * only, so per-account forwardings are unbounded in v1.
 */
final class StoreMailboxForwardingRequest extends FormRequest
{
    use NormalisesAddresses;

    /**
     * Decided on the owning mailbox, never on the destination: a forwarding
     * may legitimately point into a domain the actor does not administer
     * (BR-02, BR-18, Q4 answered option C).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->mailbox()) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'forwarding' => [
                // Format is checked always, external target or not (BR-18.1).
                'required', 'string', 'max:255', 'email:rfc',

                /*
                 * BR-07, in the canonical lower case form NormalisesAddresses
                 * has already produced. The database index is not relied upon:
                 * it catches case duplicates on MySQL and misses them on
                 * PostgreSQL.
                 *
                 * This also refuses a forwarding to the account's own address,
                 * because the self-referencing row of BR-06 already holds that
                 * pair. AC-24 reads as though such a request should succeed;
                 * it cannot, since `(address, forwarding)` is unique and the
                 * row is already there. What AC-24 actually forbids — walking
                 * the forwarding graph to refuse loops — is not done: mutual
                 * forwardings between two accounts are accepted.
                 */
                Rule::unique('vmail.forwardings', 'forwarding')
                    ->where('address', (string) $this->mailbox()->getKey()),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['forwarding'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = (string) $this->input('forwarding');
            $domain = Str::afterLast($target, '@');

            if ($domain === '' || $domain === $target) {
                return;
            }

            /*
             * BR-18.2. A target whose domain has no `domain` row is external
             * and is accepted on format alone — forwarding mail off the server
             * is the ordinary use of a forwarding, and restricting the
             * destination is what a hosting provider does to a customer, which
             * `docs/00-overview.md` §3 rules out. A domain that exists only as
             * an `alias_domain` row is external for this rule too: an alias
             * domain has no accounts of its own, so there is no row to check
             * the target against.
             *
             * Read through the query builder rather than the Domain model on
             * purpose. The model is domain-scoped, and locality here is a fact
             * about the server, not about the actor's scope: a target in a
             * domain the actor does not administer is still local and must
             * still exist (BR-02, AC-26).
             */
            $local = DB::connection('vmail')->table('domain')->where('domain', $domain)->exists();

            if (! $local) {
                return;
            }

            if (! $this->targetExists($target)) {
                $validator->errors()->add(
                    'forwarding',
                    __('No account with that address exists on this server. Check the address, or forward to an external one.'),
                );
            }
        });
    }

    /**
     * BR-18.3 — a local target must exist, and **which tables are consulted is
     * part of the rule**. Collisions between the three are permitted, so a
     * check written against `mailbox` alone would silently reject valid
     * targets. Any one of them ends the check.
     *
     * `active = 0` on the matched row does not fail it: a disabled but
     * existing target is a state an administrator chose, not a typo.
     *
     * Every query is anchored on an address column and never on a flag alone —
     * MySQL has no index on `is_forwarding` where PostgreSQL does (BR-09,
     * matrix D15).
     */
    private function targetExists(string $target): bool
    {
        $vmail = DB::connection('vmail');

        if ($vmail->table('mailbox')->where('username', $target)->exists()) {
            return true;
        }

        if ($vmail->table('alias')->where('address', $target)->exists()) {
            return true;
        }

        /*
         * A per-account alias written by this feature. The flag is compared
         * against the integer, never a PHP boolean: the column is INT2 on
         * PostgreSQL, which has no implicit cast from boolean (BR-08, D4).
         *
         * `address` is the alias address here — see AddMailboxAliasAction for
         * the reading of OQ-A1 this rests on.
         */
        return $vmail->table('forwardings')
            ->where('address', $target)
            ->where('is_alias', 1)
            ->exists();
    }

    /**
     * The account these rows belong to, resolved from the route before
     * anything else: authorization is decided on it (BR-02).
     */
    public function mailbox(): Mailbox
    {
        $mailbox = $this->route('mailbox');

        if (! $mailbox instanceof Mailbox) {
            abort(404);
        }

        return $mailbox;
    }
}
