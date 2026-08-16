<?php

declare(strict_types=1);

namespace App\Http\Requests\AliasDomains;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\AliasDomain;
use App\Models\Mail\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreAliasDomainRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        return $this->user()?->can('create', AliasDomain::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'alias_domain' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',

                // BR-04: an explicit query, not a unique index — MySQL's
                // collation catches a case duplicate and PostgreSQL's does not.
                Rule::unique('vmail.alias_domain', 'alias_domain'),

                /*
                 * BR-13 deliberately permits a name to be both an alias domain
                 * and a real domain, so there is no check against `domain`
                 * here. Only pointing at itself is refused, which would be a
                 * loop rather than a configuration.
                 */
                'different:target_domain',
            ],

            /*
             * BR-02: the target must exist. Nothing in the schema enforces it,
             * and an alias domain pointing at a domain the server does not
             * host accepts mail it cannot deliver.
             */
            'target_domain' => [
                'required', 'string', 'max:255',
                Rule::exists('vmail.domain', 'domain'),
            ],

            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['alias_domain', 'target_domain'];
    }

    /**
     * The target must also be in the actor's scope (BR-05). Checked here
     * rather than in a rule so the refusal is an authorization failure, and
     * so a global admin is not asked to prove anything.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = (string) $this->input('target_domain');

            if ($target === '' || Domain::query()->whereKey($target)->exists()) {
                return;
            }

            // The scoped query found nothing, so either it does not exist —
            // already reported by the exists rule — or it is not the actor's.
            $validator->errors()->add('target_domain', __('That domain is not one you administer.'));
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alias_domain.regex' => __('That is not a valid domain name.'),
            'alias_domain.unique' => __('This alias domain already exists.'),
            'alias_domain.different' => __('An alias domain cannot point at itself.'),
            'target_domain.exists' => __('That domain does not exist on this server.'),
        ];
    }
}
