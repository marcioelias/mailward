<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InspectMailBackend;
use Inertia\Inertia;
use Inertia\Response;

final class StatusController extends Controller
{
    public function __invoke(InspectMailBackend $inspectMailBackend): Response
    {
        return Inertia::render('Status', [
            'backend' => $inspectMailBackend->handle(),
        ]);
    }
}
