<?php

declare(strict_types=1);

namespace App\Http\Requests\AliasDomains;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The name is not editable: it is the primary key and renaming is out of scope
 * (`docs/features/alias-domains.md` BR-14). Only the target and `active`.
 */
final class UpdateAliasDomainRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('aliasDomain')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
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
        return ['target_domain'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = (string) $this->input('target_domain');

            if ($target === '' || Domain::query()->whereKey($target)->exists()) {
                return;
            }

            $validator->errors()->add('target_domain', __('That domain is not one you administer.'));
        });
    }
}
