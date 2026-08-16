<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'status' => fn () => $request->session()->get('status'),

            // For the user menu, and for showing or hiding controls. The
            // server authorises again on every request whatever the UI
            // offered (docs/policies/authorization.md §4).
            'actor' => fn (): ?array => $request->user() === null ? null : [
                'address' => $request->user()->getAuthIdentifier(),
                'name' => $request->user()->name,
                'isGlobalAdmin' => (bool) $request->user()->isglobaladmin,
            ],
            //
        ];
    }
}
