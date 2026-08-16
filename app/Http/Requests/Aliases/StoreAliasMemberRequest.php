<?php

declare(strict_types=1);

namespace App\Http\Requests\Aliases;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Alias;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Adding one member to a standalone alias.
 *
 * Members expose create and delete only — there is no request that changes a
 * member row, and none that writes `forwardings.active`
 * (`docs/features/aliases.md` BR-21).
 *
 * Authorisation is the alias's, not the member's: a member may sit in a domain
 * the actor does not administer, or off the server entirely (BR-12, BR-19).
 */
final class StoreAliasMemberRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        $alias = $this->route('alias');

        return $alias instanceof Alias && ($this->user()?->can('update', $alias) ?? false);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // Format, always — external members included (BR-19.1).
        return [
            'forwarding' => ['required', 'string', 'max:255', 'email:rfc'],
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
            $alias = $this->route('alias');
            $member = (string) $this->input('forwarding');

            if (! $alias instanceof Alias || $member === '') {
                return;
            }

            /*
             * `forwardings` is unique on `(address, forwarding)` across all
             * four purposes the table serves, so the pair is checked without a
             * flag filter and anchored on `address`, which is indexed on both
             * drivers (BR-04, BR-05). A repeated submission is a validation
             * error rather than a driver error, and never a second row.
             */
            $duplicate = DB::connection('vmail')->table('forwardings')
                ->where('address', $alias->getKey())
                ->where('forwarding', $member)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('forwarding', __('That address is already a member of this alias.'));

                return;
            }

            $this->checkLocality($validator, $member);
        });
    }

    /**
     * A member is *local* when its domain part has a row in `domain`, and a
     * local member must exist. A member whose domain has no `domain` row is
     * external and is accepted on format alone: distribution to addresses off
     * this server is the ordinary use of an alias. A domain that exists only as
     * an `alias_domain` row is external for this rule — it has no accounts of
     * its own, so there is no row to check the member against, and refusing it
     * would reject an address the server does deliver (BR-19.2).
     *
     * **Read through the query builder, never through the scoped `Domain`
     * model.** The actor's scope decides who may write this alias; it must not
     * decide which destinations exist, or a domain admin would find every
     * address outside their own domains treated as external (BR-12, AC-27).
     *
     * No circular or self-referencing check is performed: an alias may name
     * itself or form a loop with another, and the graph walk that would catch
     * it still could not see loops formed through per-account aliases and
     * external hops (BR-19).
     */
    private function checkLocality(Validator $validator, string $member): void
    {
        $domain = Str::afterLast($member, '@');

        $local = DB::connection('vmail')->table('domain')->where('domain', $domain)->exists();

        if (! $local || $this->existsLocally($member)) {
            return;
        }

        $validator->errors()->add(
            'forwarding',
            __('No account with that address exists on this server.'),
        );
    }

    /**
     * Which tables the check consults is part of the rule rather than an
     * implementation detail. Because collisions are permitted (BR-17), "the
     * local address exists" can be true of more than one object at once, and a
     * check written against `mailbox` alone would silently reject valid
     * targets. **Any one** of the three satisfies it, and the first match ends
     * the check; every query is anchored on an address column and never on a
     * flag alone (BR-19.3, BR-05).
     *
     * `is_maillist` rows do not satisfy it — mailing lists are unmodelled in v1
     * — and `active = 0` on the matched row does not fail it: an existing but
     * disabled member is a state an administrator chose, not a typo.
     */
    private function existsLocally(string $member): bool
    {
        $vmail = DB::connection('vmail');

        return $vmail->table('mailbox')->where('username', $member)->exists()
            || $vmail->table('alias')->where('address', $member)->exists()
            || $vmail->table('forwardings')->where('address', $member)->where('is_alias', 1)->exists();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'forwarding.email' => __('That is not a valid mail address.'),
        ];
    }
}
