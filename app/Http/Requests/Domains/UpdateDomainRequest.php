<?php

declare(strict_types=1);

namespace App\Http\Requests\Domains;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The domain name itself is not editable here. It is the primary key and is
 * denormalised into seven other tables with no foreign key to propagate a
 * change, so renaming is a separate question the specification has not
 * answered (`docs/features/domains.md` OQ-DOM-08).
 */
final class UpdateDomainRequest extends FormRequest
{
    private const MAX_LIMIT = 2147483647;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('domain')) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:65535'],
            'disclaimer' => ['nullable', 'string', 'max:65535'],
            'aliases' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'mailboxes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'maillists' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LIMIT],
            'maxquota' => ['required', 'integer', 'min:0'],
            'backupmx' => ['sometimes', 'boolean'],
        ];
    }
}
