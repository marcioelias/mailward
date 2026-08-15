<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use App\Support\PasswordScheme\UnsupportedScheme;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a submitted credential may enter the panel.
 *
 * Two steps, and conflating them is specified as a critical bug
 * (docs/policies/authorization.md §6, docs/features/authentication.md BR-03):
 *
 * 1. The password is verified against `vmail.mailbox`. **Every mail account on
 *    the server passes this step** — it is the same password as IMAP.
 * 2. Access is granted only to an account carrying `isadmin` or
 *    `isglobaladmin`.
 *
 * Every failure returns null. The caller has exactly one message for all of
 * them, because the login form is a password oracle against real mail accounts
 * and the difference between "no such address" and "not an administrator" is
 * precisely what an attacker enumerating the server wants.
 */
final class AuthenticateAdministrator
{
    public function __construct(private readonly SchemeRegistry $schemes) {}

    public function handle(string $address, string $password): ?Mailbox
    {
        /*
         * Canonicalised again here, not only at the request boundary
         * (ADR-0005). Without it this lookup is driver-dependent: MySQL's
         * utf8mb4_general_ci matches a mixed-case address by accident while
         * PostgreSQL does not, so the same call would admit an account on one
         * driver and deny it on the other. Consoles and tests reach this
         * method without passing through a FormRequest.
         */
        $address = mb_strtolower(trim($address));

        if ($address === '' || $password === '') {
            return null;
        }

        /*
         * Explicitly unscoped, and this is one of the two legitimate uses the
         * scope documents: authentication runs before an actor exists, so the
         * deny-by-default filter would refuse every login including the very
         * first one (docs/policies/authorization.md §3).
         */
        $mailbox = Mailbox::query()->withoutDomainScope()->find($address);

        if (! $mailbox instanceof Mailbox) {
            /*
             * Spend the time anyway. Returning early here makes an unknown
             * address measurably faster than a wrong password, which turns the
             * response time into the existence oracle the generic message
             * exists to prevent.
             */
            $this->schemes->verify($password, $this->decoyHash());

            return null;
        }

        if (! $this->passwordMatches($mailbox, $password)) {
            return null;
        }

        if (! $this->isUsable($mailbox)) {
            return null;
        }

        if (! $mailbox->isadmin && ! $mailbox->isglobaladmin) {
            /*
             * Recorded as an authorisation failure rather than an
             * authentication one: the credentials were correct
             * (docs/policies/authorization.md §6).
             */
            Log::warning('Authorisation failure at login: account is not an administrator.', [
                'address' => $address,
            ]);

            return null;
        }

        return $mailbox;
    }

    private function passwordMatches(Mailbox $mailbox, string $password): bool
    {
        $stored = (string) $mailbox->getAttribute('password');

        try {
            return $this->schemes->verify($password, $stored);
        } catch (UnsupportedScheme $e) {
            /*
             * The row uses a format we cannot read. This is not a wrong
             * password, and treating it as one would tell an administrator
             * their credentials are wrong while their mail keeps working
             * (docs/decisions/0007). The caller still shows the generic
             * message — an anonymous caller learning that this address exists
             * would defeat §6 — so the operator is told here instead.
             */
            Log::error('Password scheme cannot be verified; this account cannot sign in.', [
                'address' => $mailbox->getAuthIdentifier(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * `active = 0` or an expiry in the past denies access at both steps
     * (docs/policies/authorization.md §6).
     *
     * Expiry is a comparison against now, never equality against a sentinel:
     * the two drivers ship different "never expires" values
     * (docs/reference/schema-type-matrix.md, D2).
     */
    private function isUsable(Mailbox $mailbox): bool
    {
        if (! $mailbox->active) {
            return false;
        }

        return $mailbox->expired === null || $mailbox->expired->isFuture();
    }

    /**
     * A well-formed hash in the generated scheme, used only to keep the
     * unknown-address path as expensive as the known one.
     */
    private function decoyHash(): string
    {
        return $this->schemes->hash('mailward-timing-decoy');
    }
}
