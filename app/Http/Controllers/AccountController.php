<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Mailboxes\UpdateMailboxAction;
use App\Http\Requests\Account\UpdateAccountPasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The acting administrator's own account.
 *
 * **Exempt from the domain scope, and deliberately so.** An administrator's own
 * mailbox may sit in a domain they do not administer — a global admin demoted
 * to one domain, or an account whose domain was later reassigned. Without this
 * they would be hidden from themselves and unable to rotate the one password
 * they must always be able to rotate
 * (`docs/features/authentication.md` BR-23).
 *
 * The exemption covers exactly one row: the one whose `username` equals the
 * acting address. It widens nothing else.
 */
final class AccountController extends Controller
{
    public function edit(): Response
    {
        $mailbox = $this->own();

        return Inertia::render('Account/Edit', [
            'account' => [
                'username' => $mailbox->getKey(),
                'name' => $mailbox->name,
                'domain' => $mailbox->domain,
                'isglobaladmin' => $mailbox->isglobaladmin,
                'weakness' => app(SchemeRegistry::class)->weakness((string) $mailbox->getAttribute('password')),
            ],
        ]);
    }

    /**
     * Display name only. Quota, service toggles, `active` and the
     * administrator flags are administrative decisions *about* an account, and
     * letting an actor make them about themselves reintroduces exactly the
     * self-escalation BR-A02 exists to prevent (BR-24).
     */
    public function update(UpdateAccountRequest $request, UpdateMailboxAction $updateMailbox): RedirectResponse
    {
        $updateMailbox->handle($this->own(), ['name' => $request->validated('name')]);

        return back()->with('status', __('Your display name was updated.'));
    }

    public function updatePassword(
        UpdateAccountPasswordRequest $request,
        UpdateMailboxAction $updateMailbox,
    ): RedirectResponse {
        $updateMailbox->setPassword($this->own(), (string) $request->validated('password'));

        return back()->with('status', __('Your mail password was changed. It now applies to IMAP, SMTP and webmail.'));
    }

    /**
     * The acting administrator's own row, read without the domain scope.
     */
    private function own(): Mailbox
    {
        $address = Auth::user()?->getAuthIdentifier();

        return Mailbox::query()
            ->withoutDomainScope()
            ->findOrFail($address);
    }
}
