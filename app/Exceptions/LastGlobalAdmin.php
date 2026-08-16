<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a change would leave the panel with no global admin at all.
 *
 * BR-A01 (`docs/policies/authorization.md` §5) is the rule that keeps the
 * organisation out of a locked panel, and it is a domain invariant rather than
 * an authorization decision: the actor is permitted, the resulting state is
 * not. It is therefore raised from inside the transaction that performs the
 * change, so the refusal and the rollback are the same event.
 *
 * The recovery path when it happens anyway is the escape hatch, not this
 * message: `php artisan mailward:promote <address> --global` (BR-A04).
 */
final class LastGlobalAdmin extends RuntimeException
{
    public static function cannotBeDemoted(string $address): self
    {
        return new self(
            "{$address} is the only global administrator left. Promote another account first, or the panel would have no administrator."
        );
    }

    /**
     * Rendered as a refusal on the form that attempted it. Inertia surfaces
     * `errors` on the page it returns to, which is where an administrator can
     * act on it.
     */
    public function render(Request $request): RedirectResponse
    {
        return back()->withErrors(['global' => $this->getMessage()]);
    }
}
