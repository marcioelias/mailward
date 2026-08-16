<?php

declare(strict_types=1);

namespace App\Http\Requests\Aliases;

use App\Models\Mail\Alias;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The address is not editable: it is the primary key and it is also the value
 * every member row's `address` carries, so renaming is out of scope
 * (`docs/features/aliases.md`, Out of Scope). Only `name`, `accesspolicy` and
 * `active` change.
 *
 * The trait that lower-cases addresses is deliberately absent — this request
 * carries none (BR-07 applies to addresses, and the only one here is in the
 * URL, resolved by the route binding).
 */
final class UpdateAliasRequest extends FormRequest
{
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
        return [
            'name' => ['nullable', 'string', 'max:255'],

            // Free text, unconstrained at the schema level. No allowlist until
            // OQ-AL-01 is answered (BR-11).
            'accesspolicy' => ['nullable', 'string', 'max:30'],

            'active' => ['required', 'boolean'],
        ];
    }
}
