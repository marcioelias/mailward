<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the display name. Everything else about an account is an
 * administrative decision, and an actor making those about themselves is the
 * self-escalation BR-A02 forbids (`docs/features/authentication.md` BR-24).
 */
final class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is inside the authenticated group and acts only on the
        // acting address, so being signed in is the whole rule.
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
