<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Mail\Mailbox;
use App\Support\Authorization\AdministratorGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session whose account is no longer entitled to one.
 *
 * Without this the gate runs at login and never again, so demoting an
 * administrator, deactivating their account or deleting it leaves them working
 * inside the panel until they choose to sign out — which contradicts
 * `docs/features/authentication.md` BR-18 and makes the demotion decided in
 * Q7 take effect at some unspecified later time.
 *
 * The cost is one primary-key lookup on a connection every request already
 * uses, and the model has in fact already been fetched by the user provider to
 * rebuild the session. Failing closed is the point: a revocation an
 * administrator performed should be true by the revoked account's next click,
 * not by their next voluntary sign-out (Q13, answered 2026-08-15).
 */
final class EnsureStillAnAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof Mailbox && ! AdministratorGate::passes($user)) {
            /*
             * Recorded as an authorization failure rather than an
             * authentication one: the session was valid, the entitlement is
             * not (docs/policies/authorization.md §6).
             */
            Log::warning('Session ended: the account no longer qualifies for the panel.', [
                'address' => $user->getAuthIdentifier(),
            ]);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
