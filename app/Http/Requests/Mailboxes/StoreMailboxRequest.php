<?php

declare(strict_types=1);

namespace App\Http\Requests\Mailboxes;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Support\Quota\DomainAllowance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreMailboxRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Mailbox::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required', 'string', 'max:255', 'email:rfc',
                Rule::unique('vmail.mailbox', 'username'),
            ],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'name' => ['nullable', 'string', 'max:255'],

            // Mebibytes, and zero means unlimited (BR-13).
            'quota' => ['required', 'integer', 'min:0'],

            'active' => ['sometimes', 'boolean'],
            'services' => ['sometimes', 'array'],
            'services.*' => ['boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['username'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $address = (string) $this->input('username');
            $domain = Str::afterLast($address, '@');

            if ($domain === '' || $domain === $address) {
                return;
            }

            /*
             * The domain must exist as a real domain, and be one the actor may
             * see — the scoped query answers both at once. A row in
             * `alias_domain` does not satisfy it: an alias domain has no
             * accounts of its own, so a mailbox created inside one would be
             * unreachable (BR-26).
             */
            if (! Domain::query()->whereKey($domain)->exists()) {
                $validator->errors()->add(
                    'username',
                    __('That domain does not exist on this server, or is not one you administer.'),
                );

                return;
            }

            $this->checkAllowances($validator, $domain);
        });
    }

    /**
     * Both limits are guardrails counted without a lock, so the message names
     * what is left rather than pretending the number is authoritative.
     */
    private function checkAllowances(Validator $validator, string $domain): void
    {
        $slots = DomainAllowance::mailboxes($domain);

        if ($slots !== null && $slots < 1) {
            $validator->errors()->add('username', __('This domain has reached its mailbox limit.'));
        }

        $requested = (int) $this->input('quota', 0);
        $remaining = DomainAllowance::quota($domain);

        /*
         * iRedMail truncates silently here — it reduces the quota to whatever
         * is left and creates the account anyway. Mailward refuses and names
         * the balance: granting a different quota than the one typed is the
         * same looks-like-it-worked defect this project guards against
         * (docs/features/mailboxes.md BR-28).
         */
        if ($remaining !== null && $requested > $remaining) {
            $validator->errors()->add('quota', __('Only :mib MiB remain in this domain\'s quota pool.', [
                'mib' => $remaining,
            ]));
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.unique' => __('An account with this address already exists.'),
            'password.confirmed' => __('The two passwords do not match.'),
        ];
    }
}
