<?php

declare(strict_types=1);

namespace App\Http\Requests\Domains;

use App\Http\Requests\Concerns\NormalisesAddresses;
use App\Models\Mail\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDomainRequest extends FormRequest
{
    use NormalisesAddresses;

    /** The 32-bit signed maximum: the limit columns are INT on MySQL (BR-12). */
    private const MAX_LIMIT = 2147483647;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Domain::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Uniqueness is an explicit query rather than a unique index:
             * MySQL's collation catches a case duplicate and PostgreSQL's does
             * not, so the index is not something to rely on (BR-02, ADR-0005).
             * The value is already lower case by the time this runs.
             */
            'domain' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
                Rule::unique('vmail.domain', 'domain'),
            ],
            'description' => ['nullable', 'string', 'max:65535'],
            'disclaimer' => ['nullable', 'string', 'max:65535'],
            'aliases' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'mailboxes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'maillists' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'maxquota' => ['required', 'integer', 'min:0'],
            'backupmx' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
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
            'domain.regex' => __('That is not a valid domain name.'),
            'domain.unique' => __('This domain already exists on the server.'),
        ];
    }
}
