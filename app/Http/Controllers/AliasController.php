<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Aliases\DeleteAliasAction;
use App\Actions\Aliases\SaveAliasAction;
use App\Http\Requests\Aliases\StoreAliasMemberRequest;
use App\Http\Requests\Aliases\StoreAliasRequest;
use App\Http\Requests\Aliases\UpdateAliasRequest;
use App\Models\Mail\Alias;
use App\Models\Mail\Domain;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Standalone alias accounts and their members.
 *
 * The account is a row in `alias`; its members are rows in `forwardings` with
 * `is_list = 1` (`docs/features/aliases.md` BR-01, BR-02). Presenting them as
 * one screen is the feature; conflating them in the data layer is the defect
 * the document exists to prevent, so every member read and write here goes
 * through `forwardings` anchored on `address`, and no listing ever shows a
 * `forwardings` row as an alias.
 *
 * The editing screen doubles as the detail page the contracts call
 * `Aliases/Show`: the members are managed where the account is edited.
 */
final class AliasController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Alias::class);

        $search = trim((string) $request->query('search', ''));
        $domain = trim((string) $request->query('domain', ''));

        /*
         * The domain scope is applied by the model, in the query — never after
         * fetching (`docs/policies/authorization.md` §3). Rows come from
         * `alias` alone (AC-01).
         */
        $aliases = Alias::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('address', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            }))
            ->when($domain !== '', fn ($query) => $query->where('domain', $domain))
            ->orderBy('address')
            ->paginate(25)
            ->withQueryString();

        $counts = $this->memberCountsFor($aliases->pluck('address')->all());

        return Inertia::render('Aliases/Index', [
            'aliases' => $aliases->through(fn (Alias $alias): array => [
                'address' => $alias->getKey(),
                'name' => $alias->name,
                'domain' => $alias->domain,
                'active' => $alias->active,

                // Shown beside every alias so the black-hole state is never
                // inferred from an absent row (BR-20).
                'members' => $counts[(string) $alias->getKey()] ?? 0,
            ]),
            'filters' => ['search' => $search, 'domain' => $domain],
            'domains' => $this->domainOptions(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Alias::class);

        return Inertia::render('Aliases/Form', [
            'alias' => null,
            'members' => [],
            'domains' => $this->domainOptions(),
        ]);
    }

    public function store(StoreAliasRequest $request, SaveAliasAction $saveAlias): RedirectResponse
    {
        $alias = $saveAlias->create($request->validated());

        /*
         * Straight to the members screen, and the message says why: an alias
         * with no members accepts mail and delivers it nowhere. The state is
         * valid and is flagged rather than prevented (BR-20).
         */
        return to_route('aliases.edit', ['alias' => $alias->getKey()])
            ->with('status', __('Alias created. It delivers nowhere until you add a member.'));
    }

    public function edit(Alias $alias): Response
    {
        $this->authorizeAlias('view', $alias);

        return Inertia::render('Aliases/Form', [
            'alias' => [
                'address' => $alias->getKey(),
                'name' => $alias->name,
                'accesspolicy' => $alias->accesspolicy,
                'domain' => $alias->domain,
                'active' => $alias->active,
            ],
            'members' => $this->membersOf((string) $alias->getKey()),
            'domains' => $this->domainOptions(),
        ]);
    }

    public function update(
        UpdateAliasRequest $request,
        Alias $alias,
        SaveAliasAction $saveAlias,
    ): RedirectResponse {
        $saveAlias->update($alias, $request->validated());

        return to_route('aliases.index')->with('status', __('Alias updated.'));
    }

    public function destroy(Alias $alias, DeleteAliasAction $deleteAlias): RedirectResponse
    {
        $this->authorizeAlias('delete', $alias);

        $deleteAlias->handle($alias);

        return to_route('aliases.index')->with('status', __('Alias deleted, with its members and the rows that pointed at it.'));
    }

    public function storeMember(
        StoreAliasMemberRequest $request,
        Alias $alias,
        SaveAliasAction $saveAlias,
    ): RedirectResponse {
        $saveAlias->addMember($alias, (string) $request->validated('forwarding'));

        return back()->with('status', __('Member added.'));
    }

    /**
     * The member address arrives in the URL, so it is trimmed and lower-cased
     * here: `docs/decisions/0005-lowercase-canonical-addresses.md` applies to
     * look-ups as well as to writes (BR-07).
     */
    public function destroyMember(
        Alias $alias,
        string $forwarding,
        SaveAliasAction $saveAlias,
    ): RedirectResponse {
        $this->authorizeAlias('update', $alias);

        $saveAlias->removeMember($alias, mb_strtolower(trim($forwarding)));

        // Never refused on the ground that it was the last one: an alias whose
        // members are being replaced passes through the empty state (BR-20).
        return back()->with('status', __('Member removed.'));
    }

    /**
     * Authorises one alias and records the refusal.
     *
     * The alias is resolved without the domain scope (see
     * {@see Alias::resolveRouteBinding()}), so an alias outside the actor's
     * scope reaches the policy and is refused with a 403 rather than
     * disappearing into a 404 — and the attempt is recorded, because a domain
     * admin repeatedly probing another domain's resources is exactly what the
     * log exists to show (AC-11, `docs/policies/authorization.md` §7).
     *
     * No before/after values: nothing changed
     * (`docs/features/audit-log.md` BR-12).
     */
    private function authorizeAlias(string $ability, Alias $alias): void
    {
        if (Gate::denies($ability, $alias)) {
            Audit::record('authorization-failed', $alias, description: $ability.' denied');

            abort(403);
        }
    }

    /**
     * Member counts for the listed aliases, in one query grouped on
     * `forwardings.address` — indexed on both drivers, and never filtered on a
     * flag alone (BR-05).
     *
     * Read through the query builder rather than through a `withCount` on the
     * relation: the `Forwarding` model is domain-scoped on `forwardings.domain`,
     * a column whose population rule is undecided (OQ-AL-02), and scoping a
     * member row by the member's own domain is precisely what BR-12 forbids.
     *
     * @param  list<string>  $addresses
     * @return array<string, int>
     */
    private function memberCountsFor(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        return DB::connection('vmail')->table('forwardings')
            ->whereIn('address', $addresses)
            ->where('is_list', 1)
            ->groupBy('address')
            ->selectRaw('address, count(*) as total')
            ->pluck('total', 'address')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * The members of one alias.
     *
     * `active` is not sent to the page. A member row is present or absent and
     * nothing else, so a value the interface offers no transition on would only
     * invite a control that BR-21 forbids — including for a row written outside
     * Mailward with `active = 0`, which is listed like any other (AC-31).
     *
     * @return list<string>
     */
    private function membersOf(string $address): array
    {
        return DB::connection('vmail')->table('forwardings')
            ->where('address', $address)
            ->where('is_list', 1)
            ->orderBy('forwarding')
            ->pluck('forwarding')
            ->map(fn ($member): string => (string) $member)
            ->all();
    }

    /**
     * The domains the actor may create an alias in. Scoped, so the form cannot
     * offer a domain the server would then refuse (BR-16).
     *
     * @return list<array{value: string, label: string}>
     */
    private function domainOptions(): array
    {
        return Domain::query()->orderBy('domain')->pluck('domain')
            ->map(fn (string $domain): array => ['value' => $domain, 'label' => $domain])
            ->all();
    }
}
