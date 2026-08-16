<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InspectMailBackendAction;
use Inertia\Inertia;
use Inertia\Response;

final class StatusController extends Controller
{
    public function __invoke(InspectMailBackendAction $inspectMailBackend): Response
    {
        return Inertia::render('Status', [
            'backend' => $inspectMailBackend->handle(),
        ]);
    }
}
