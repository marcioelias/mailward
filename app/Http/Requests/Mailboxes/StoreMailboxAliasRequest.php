<?php

declare(strict_types=1);

namespace App\Http\Requests\Mailboxes;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A per-user alias — an extra address that delivers into an existing account
 * (`docs/features/mailbox-aliases-forwardings.md`, `is_alias = 1`).
 *
 * **No limit is checked here, and that is a decision rather than an omission**
 * (BR-15). `domain.aliases` bounds standalone alias accounts in `vmail.alias`
 * only, so per-account aliases are unbounded in v1.
 */
final class StoreMailboxAliasRequest extends FormRequest
{
    use NormalisesAddresses;

    /**
     * The owning mailbox decides this, never the alias address: these rows are
     * the contents of an account, and a domain admin may write the contents of
     * an account in a domain they administer (BR-02).
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
            'address' => [
                'required', 'string', 'max:255', 'email:rfc',

                /*
                 * BR-07. `(address, forwarding)` is unique on both drivers, but
                 * the index is not what this relies on: it catches case
                 * duplicates on MySQL and misses them on PostgreSQL. The pair is
                 * compared here in the canonical lower case form, which
                 * NormalisesAddresses has already produced.
                 */
                Rule::unique('vmail.forwardings', 'address')
                    ->where('forwarding', (string) $this->mailbox()->getKey()),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['address'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $address = (string) $this->input('address');
            $domain = Str::afterLast($address, '@');

            if ($domain === '' || $domain === $address) {
                return;
            }

            /*
             * BR-16: an alias address names an address this server is expected
             * to accept, so its domain must already be hosted here. A row in
             * `alias_domain` does not satisfy it — an alias domain has no
             * accounts of its own — and without this check a typo produces a
             * silently dead address.
             *
             * The query is the domain-scoped one, exactly as
             * StoreMailboxRequest uses for the same rule on a mailbox address:
             * it answers "does this domain exist" and "may the actor write into
             * it" at once, which is the scope rule of
             * `docs/policies/authorization.md` §2 and §3. A forwarding
             * *target* is treated differently and deliberately so — see
             * StoreMailboxForwardingRequest.
             */
            if (! Domain::query()->whereKey($domain)->exists()) {
                $validator->errors()->add(
                    'address',
                    __('That domain does not exist on this server, or is not one you administer.'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.unique' => __('This account already has that alias.'),
        ];
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
