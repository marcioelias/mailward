<?php

declare(strict_types=1);

namespace App\Http\Requests\DomainAdmins;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning one domain to one administrator — `POST /admins/{address}/domains`,
 * and the same shape reused for the initial grants of `POST /admins`.
 *
 * The address itself arrives as a bound route parameter rather than as input,
 * so only `domain` is normalised here; the route parameter is lower-cased by
 * the controller for the same reason (BR-08).
 */
final class StoreDomainAdminRequest extends FormRequest
{
    use NormalisesAddresses;

    /**
     * BR-15: every write in this feature is global-admin only, including a
     * grant on a domain the actor already administers.
     */
    public function authorize(): bool
    {
        $administrator = Mailbox::withoutDomainScope()
            ->whereKey(mb_strtolower(trim((string) $this->route('address'))))
            ->first();

        if (! $administrator instanceof Mailbox) {
            return false;
        }

        return $this->user()?->can('manage', [DomainAdmin::class, $administrator]) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:255',

                /*
                 * BR-07: `domain_admins.domain` is CHARACTER SET ascii on
                 * MySQL, so an IDN cannot be stored there at all. It is
                 * rejected here rather than written and silently truncated
                 * (docs/reference/schema-type-matrix.md, D8). The pattern also
                 * excludes the `'ALL'` sentinel, which is uppercase and is not
                 * a domain name (BR-05).
                 */
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',

                // The domain must exist on this server. Nothing in the schema
                // enforces it, and a grant over a domain that is not hosted
                // confers authority over nothing.
                Rule::exists('vmail.domain', 'domain'),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['domain'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => __('That is not a valid domain name. Internationalised domains cannot be stored in domain_admins.'),
            'domain.exists' => __('That domain does not exist on this server.'),
        ];
    }
}
