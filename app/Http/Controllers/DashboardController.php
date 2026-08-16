<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\GatherDashboardFiguresAction;
use App\Support\Authorization\Actor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one screen that answers, for the domains the actor administers, how many
 * accounts exist, how much quota is allocated and used, which domains have
 * reached a limit, and which accounts look dormant
 * (docs/features/dashboard.md).
 *
 * There is no authorize() call and that is deliberate. Every administrator may
 * open this screen — an administrator with no domains at all gets 200 and an
 * empty dashboard rather than 403, because having no domains is a valid state
 * and not a refusal (BR-15, `docs/policies/authorization.md` BR-A03). What
 * they may *see* is decided by the domain scope inside every query the Action
 * issues (BR-01), which is where the decision belongs.
 *
 * The screen is read-only. It writes on no connection, so a successful view
 * produces no audit entry (BR-17).
 */
final class DashboardController extends Controller
{
    public function __invoke(GatherDashboardFiguresAction $gatherFigures): Response
    {
        $actor = Actor::current();

        return Inertia::render('Dashboard', [
            'figures' => $gatherFigures->handle(),

            // For showing and hiding controls only. The audit log route
            // authorises again on the server whatever this said
            // (BR-16, docs/policies/authorization.md §4).
            'can' => ['viewAuditLog' => $actor->isGlobalAdmin()],
        ]);
    }
}
