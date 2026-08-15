<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalisesAddresses;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials submitted to the login form.
 *
 * `docs/features/authentication.md` requires every denial to look identical —
 * unknown address, wrong password, inactive, expired, or no admin flag. That
 * extends to validation: a malformed address must not produce a field-level
 * error that distinguishes "not an address" from "no such account", because
 * the difference is exactly what an attacker enumerating the server wants.
 *
 * So the rules here check only that something usable was submitted. Everything
 * else is decided by the authentication attempt, which has one failure message.
 */
final class LoginRequest extends FormRequest
{
    use NormalisesAddresses;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('auth.failed'),
            'email.string' => __('auth.failed'),
            'email.max' => __('auth.failed'),
            'password.required' => __('auth.failed'),
            'password.string' => __('auth.failed'),
            'password.max' => __('auth.failed'),
        ];
    }

    /**
     * @return list<string>
     */
    protected function addressFields(): array
    {
        return ['email'];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
