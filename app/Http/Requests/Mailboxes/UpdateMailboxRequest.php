<?php

declare(strict_types=1);

namespace App\Http\Requests\Mailboxes;

use App\Models\Mail\Mailbox;
use App\Support\Quota\DomainAllowance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The address is not editable. It is the primary key, it is embedded in
 * `maildir`, in every `forwardings` row naming it, and in a directory Dovecot
 * has already created on disk.
 */
final class UpdateMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('mailbox')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'quota' => ['required', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'services' => ['sometimes', 'array'],
            'services.*' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mailbox = $this->route('mailbox');

            if (! $mailbox instanceof Mailbox) {
                return;
            }

            $requested = (int) $this->input('quota', 0);

            // The account's own current quota does not count against the pool
            // it is being measured for.
            $remaining = DomainAllowance::quota((string) $mailbox->domain, (string) $mailbox->getKey());

            if ($remaining !== null && $requested > $remaining) {
                $validator->errors()->add('quota', __('Only :mib MiB remain in this domain\'s quota pool.', [
                    'mib' => $remaining,
                ]));
            }
        });
    }
}
