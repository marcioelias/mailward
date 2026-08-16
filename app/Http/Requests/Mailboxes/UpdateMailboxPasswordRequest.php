<?php

declare(strict_types=1);

namespace App\Http\Requests\Mailboxes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing this password changes IMAP, SMTP and webmail at the same time — it
 * is the account's real mail password, not a panel credential
 * (`docs/02-domain.md` §4, `docs/features/mailboxes.md` BR-10). The interface
 * says so; this only validates.
 */
final class UpdateMailboxPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('mailbox')) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => __('The two passwords do not match.'),
        ];
    }
}
