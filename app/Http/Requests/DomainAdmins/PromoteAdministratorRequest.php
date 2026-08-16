<?php

declare(strict_types=1);

namespace App\Http\Requests\DomainAdmins;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\DomainAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Promoting an existing mail account to administrator — `POST /admins`.
 *
 * BR-01: there is no create-administrator flow. `username` names a `mailbox`
 * row that already exists, and the legacy `admin` table is never involved.
 */
final class PromoteAdministratorRequest extends FormRequest
{
    use NormalisesAddresses;

    /**
     * BR-15, and BR-11 for the flag itself: global-admin only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DomainAdmin::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required', 'string', 'max:255',

                /*
                 * BR-07: `domain_admins.username` is CHARACTER SET ascii on
                 * MySQL, so an internationalised address cannot be stored there
                 * at all and is rejected here rather than truncated
                 * (docs/reference/schema-type-matrix.md, D8).
                 */
                'regex:/^[a-z0-9._%+-]+@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',

                Rule::exists('vmail.mailbox', 'username'),
            ],

            // Optional initial grants. Each is validated exactly as a single
            // assignment is (BR-02, BR-07).
            'domains' => ['sometimes', 'array'],
            'domains.*' => [
                'string', 'max:255',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
                Rule::exists('vmail.domain', 'domain'),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['username', 'domains'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => __('That is not a valid mail address. Internationalised addresses cannot be stored in domain_admins.'),
            'username.exists' => __('No mail account exists at that address. Create the mailbox first.'),
            'domains.*.exists' => __('That domain does not exist on this server.'),
        ];
    }
}
