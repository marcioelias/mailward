<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Domains\DeleteDomain;
use App\Actions\Domains\SaveDomain;
use App\Http\Requests\Domains\StoreDomainRequest;
use App\Http\Requests\Domains\UpdateDomainRequest;
use App\Models\Mail\Domain;
use App\Support\Authorization\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Domain::class);

        $search = trim((string) $request->query('search', ''));

        /*
         * The domain scope is applied by the model, in the query — never after
         * fetching (docs/policies/authorization.md §3). With thousands of rows
         * a post-filter is both slow and one pagination bug from leaking
         * another administrator's domains.
         */
        $domains = Domain::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('domain', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            }))
            ->orderBy('domain')
            ->paginate(25)
            ->withQueryString();

        $actor = Actor::current();

        return Inertia::render('Domains/Index', [
            'domains' => $domains->through(fn (Domain $domain): array => [
                'domain' => $domain->getKey(),
                'description' => $domain->description,
                'active' => $domain->active,
                'backupmx' => $domain->backupmx,
                'limits' => [
                    'aliases' => $domain->aliases,
                    'mailboxes' => $domain->mailboxes,
                    'maillists' => $domain->maillists,
                ],
                'counts' => $this->countsFor((string) $domain->getKey()),
            ]),
            'filters' => ['search' => $search],
            // For showing and hiding controls only. The server decides again on
            // every request, whatever the UI offered (docs/policies §4).
            'can' => ['create' => $actor->isGlobalAdmin()],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Domain::class);

        return Inertia::render('Domains/Form', ['domain' => null]);
    }

    public function store(StoreDomainRequest $request, SaveDomain $saveDomain): RedirectResponse
    {
        $saveDomain->create($request->validated());

        return to_route('domains.index')->with('status', __('Domain created.'));
    }

    public function edit(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        return Inertia::render('Domains/Form', [
            'domain' => [
                'domain' => $domain->getKey(),
                'description' => $domain->description,
                'disclaimer' => $domain->disclaimer,
                'aliases' => $domain->aliases,
                'mailboxes' => $domain->mailboxes,
                'maillists' => $domain->maillists,
                'maxquota' => $domain->maxquota,
                'backupmx' => $domain->backupmx,
            ],
        ]);
    }

    public function update(UpdateDomainRequest $request, Domain $domain, SaveDomain $saveDomain): RedirectResponse
    {
        $saveDomain->update($domain, $request->validated());

        return to_route('domains.index')->with('status', __('Domain updated.'));
    }

    /**
     * Writes `active` and nothing else. Disabling deliberately leaves every
     * account inside untouched, so the operation is genuinely reversible
     * (docs/features/domains.md BR-21).
     */
    public function setActive(Request $request, Domain $domain, SaveDomain $saveDomain): RedirectResponse
    {
        $this->authorize('toggle', $domain);

        $active = $request->boolean('active');

        $saveDomain->update($domain, ['active' => $active]);

        return back()->with('status', $active ? __('Domain enabled.') : __('Domain disabled.'));
    }

    public function destroy(Domain $domain, DeleteDomain $deleteDomain): RedirectResponse
    {
        $this->authorize('delete', $domain);

        $deleteDomain->handle($domain);

        return to_route('domains.index')->with('status', __('Domain deleted.'));
    }

    /**
     * Counted per domain rather than joined: cross-database joins are
     * impossible and these are all on the same connection, but the counts feed
     * "at their limit" and must agree with what the limits bound — the alias
     * limit counts standalone alias accounts only (BR-18).
     *
     * @return array<string, int>
     */
    private function countsFor(string $domain): array
    {
        $vmail = DB::connection('vmail');

        return [
            'mailboxes' => $vmail->table('mailbox')->where('domain', $domain)->count(),
            'aliases' => $vmail->table('alias')->where('domain', $domain)->count(),
            'aliasDomains' => $vmail->table('alias_domain')->where('target_domain', $domain)->count(),
        ];
    }
}
