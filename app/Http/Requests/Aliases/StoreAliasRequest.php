<?php

declare(strict_types=1);

namespace App\Http\Requests\Aliases;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Alias;
use App\Models\Mail\Domain;
use App\Support\Quota\DomainAllowance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a standalone alias takes no member: `POST /aliases` writes the
 * `alias` row alone and members are added afterwards, which is the natural
 * order and keeps the create a single-table write
 * (`docs/features/aliases.md` BR-20).
 */
final class StoreAliasRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Alias::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Unique within `alias` only — that is the primary key. There is
             * deliberately no check against `mailbox`, `forwardings`,
             * `domain` or `alias_domain`: collisions are permitted, such a
             * check would have to fold case identically on both drivers, and
             * it would forbid arrangements a running iRedMail may resolve
             * sensibly (BR-17).
             */
            'address' => [
                'required', 'string', 'max:255', 'email:rfc',
                Rule::unique('vmail.alias', 'address'),
            ],

            'name' => ['nullable', 'string', 'max:255'],

            // BR-11: free text, VARCHAR(30), with nothing constraining it at
            // the schema level. Mailward presents no fixed list and rejects no
            // value until OQ-AL-01 is answered.
            'accesspolicy' => ['nullable', 'string', 'max:30'],

            'active' => ['sometimes', 'boolean'],
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
             * The domain part must already exist as a real domain, and be one
             * the actor may see — the scoped query answers both at once. A row
             * in `alias_domain` does not satisfy it: an alias domain has no
             * accounts of its own and its mail resolves to the target domain's
             * accounts, so an alias created inside one would be shadowed by
             * that mapping rather than reachable through it (BR-16, and the
             * same rule mailboxes carry as BR-26).
             */
            if (! Domain::query()->whereKey($domain)->exists()) {
                $validator->errors()->add(
                    'address',
                    __('That domain does not exist on this server, or is not one you administer.'),
                );

                return;
            }

            $this->checkAllowance($validator, $domain);
        });
    }

    /**
     * `domain.aliases` is a guardrail counted without a lock, so the message
     * names the limit rather than pretending the number is authoritative. It
     * bounds `alias` rows and only those — a per-account alias is a
     * `forwardings` row and an alias domain is an `alias_domain` row, and
     * neither consumes this budget (BR-06, BR-15).
     */
    private function checkAllowance(Validator $validator, string $domain): void
    {
        $slots = DomainAllowance::aliases($domain);

        if ($slots !== null && $slots < 1) {
            $validator->errors()->add('address', __('This domain has reached its alias limit.'));
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.email' => __('That is not a valid mail address.'),
            'address.unique' => __('An alias with this address already exists.'),
        ];
    }
}
