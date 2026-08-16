<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AliasDomains\SaveAliasDomainAction;
use App\Http\Requests\AliasDomains\StoreAliasDomainRequest;
use App\Http\Requests\AliasDomains\UpdateAliasDomainRequest;
use App\Models\Mail\AliasDomain;
use App\Models\Mail\Domain;
use App\Support\Authorization\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AliasDomainController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AliasDomain::class);

        $search = trim((string) $request->query('search', ''));
        $target = trim((string) $request->query('target_domain', ''));

        // Scoped by the model's global scope, on `target_domain` (BR-05).
        $aliasDomains = AliasDomain::query()
            ->when($search !== '', fn ($query) => $query->where('alias_domain', 'like', '%'.$search.'%'))
            ->when($target !== '', fn ($query) => $query->where('target_domain', $target))
            ->orderBy('alias_domain')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('AliasDomains/Index', [
            'aliasDomains' => $aliasDomains->through(fn (AliasDomain $aliasDomain): array => [
                'alias_domain' => $aliasDomain->getKey(),
                'target_domain' => $aliasDomain->target_domain,
                'active' => $aliasDomain->active,
            ]),
            'filters' => ['search' => $search, 'target_domain' => $target],
            'targets' => $this->availableTargets(),
            'can' => ['create' => Actor::current()->isGlobalAdmin()],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', AliasDomain::class);

        return Inertia::render('AliasDomains/Form', [
            'aliasDomain' => null,
            'targets' => $this->availableTargets(),
        ]);
    }

    public function store(StoreAliasDomainRequest $request, SaveAliasDomainAction $saveAliasDomain): RedirectResponse
    {
        $saveAliasDomain->create($request->validated());

        return to_route('alias-domains.index')->with('status', __('Alias domain created.'));
    }

    public function edit(AliasDomain $aliasDomain): Response
    {
        $this->authorize('update', $aliasDomain);

        return Inertia::render('AliasDomains/Form', [
            'aliasDomain' => [
                'alias_domain' => $aliasDomain->getKey(),
                'target_domain' => $aliasDomain->target_domain,
                'active' => $aliasDomain->active,
            ],
            'targets' => $this->availableTargets(),
        ]);
    }

    public function update(
        UpdateAliasDomainRequest $request,
        AliasDomain $aliasDomain,
        SaveAliasDomainAction $saveAliasDomain,
    ): RedirectResponse {
        $saveAliasDomain->update($aliasDomain, $request->validated());

        return to_route('alias-domains.index')->with('status', __('Alias domain updated.'));
    }

    public function setActive(
        Request $request,
        AliasDomain $aliasDomain,
        SaveAliasDomainAction $saveAliasDomain,
    ): RedirectResponse {
        $this->authorize('toggle', $aliasDomain);

        $active = $request->boolean('active');

        $saveAliasDomain->update($aliasDomain, ['active' => $active]);

        return back()->with('status', $active ? __('Alias domain enabled.') : __('Alias domain disabled.'));
    }

    public function destroy(AliasDomain $aliasDomain, SaveAliasDomainAction $saveAliasDomain): RedirectResponse
    {
        $this->authorize('delete', $aliasDomain);

        $saveAliasDomain->delete($aliasDomain);

        return to_route('alias-domains.index')->with('status', __('Alias domain deleted.'));
    }

    /**
     * The domains the actor may point an alias domain at. Scoped, so the form
     * cannot offer a target the server would then refuse.
     *
     * Shaped for the select rather than as bare strings: the component's
     * contract is value and label, and building that here keeps the page free
     * of a mapping that only exists to satisfy a component.
     *
     * @return list<array{value: string, label: string}>
     */
    private function availableTargets(): array
    {
        return Domain::query()
            ->orderBy('domain')
            ->pluck('domain')
            ->map(fn (string $domain): array => ['value' => $domain, 'label' => $domain])
            ->all();
    }
}
