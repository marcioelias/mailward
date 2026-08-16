<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DomainAdmins\DemoteAdministratorAction;
use App\Actions\DomainAdmins\GrantDomainAction;
use App\Actions\DomainAdmins\PromoteToGlobalAdminAction;
use App\Actions\DomainAdmins\RevokeDomainAction;
use App\Http\Requests\DomainAdmins\PromoteAdministratorRequest;
use App\Http\Requests\DomainAdmins\StoreDomainAdminRequest;
use App\Models\Mail\Domain;
use App\Models\Mail\DomainAdmin;
use App\Models\Mail\Mailbox;
use App\Models\Mail\MailModel;
use App\Models\Mail\Scopes\DomainScope;
use App\Policies\Mail\DomainAdminPolicy;
use App\Support\Authorization\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrators — `docs/features/domain-admins.md`.
 *
 * Every write here is global-admin only (BR-15), enforced by
 * {@see DomainAdminPolicy} rather than by the route. A
 * domain admin reaches the listing and sees the grants covering their own
 * domains.
 *
 * **No query in this controller joins `domain_admins` to `domain`.** The two
 * key columns are `CHARACTER SET ascii` on MySQL while `domain.domain` is
 * `utf8mb4`, so the join raises an illegal-mix-of-collations error (BR-06,
 * matrix D8). The two tables are read separately and correlated in PHP, as the
 * rest of the project already does.
 */
final class DomainAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DomainAdmin::class);

        $actor = Actor::current();
        $search = trim((string) $request->query('search', ''));

        $administrators = $this->administrators($actor, $search);

        /*
         * Scoped by the actor, so a domain admin's view of any administrator's
         * reach is bounded by their own. This is also what BR-05 is about: a
         * global admin holds one `'ALL'` row, which names no domain, so their
         * list is every domain rather than zero rows.
         */
        $visibleDomains = Domain::query()->orderBy('domain')->pluck('domain')
            ->map(strval(...))
            ->all();

        $grants = $this->grantsFor($administrators->pluck('username')->map(strval(...))->all());

        $globalAdminCount = $this->globalAdminCount();

        return Inertia::render('DomainAdmins/Index', [
            'administrators' => $administrators->through(
                fn (Mailbox $administrator): array => $this->present(
                    $administrator,
                    $administrator->isglobaladmin ? $visibleDomains : ($grants[(string) $administrator->getKey()] ?? []),
                    $actor,
                    $globalAdminCount,
                )
            ),
            'filters' => ['search' => $search],
            'can' => ['create' => $actor->isGlobalAdmin()],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', DomainAdmin::class);

        return Inertia::render('DomainAdmins/Form', [
            'administrator' => null,
            'domains' => $this->domainOptions(),
            'candidates' => $this->promotableAccounts(),
        ]);
    }

    /**
     * Promote an account to administrator, with optional initial domains
     * (BR-01). Both writes land in one transaction inside the action.
     */
    public function store(PromoteAdministratorRequest $request, GrantDomainAction $grantDomain): RedirectResponse
    {
        $administrator = $this->administrator((string) $request->validated('username'));

        /** @var list<string> $domains */
        $domains = (array) $request->validated('domains', []);

        $grantDomain->handle($administrator, $domains);

        return to_route('admins.index')->with('status', __('Administrator promoted.'));
    }

    public function edit(string $address): Response
    {
        $administrator = $this->administrator($address);

        $this->authorize('manage', [DomainAdmin::class, $administrator]);

        return Inertia::render('DomainAdmins/Form', [
            'administrator' => $this->present(
                $administrator,
                $administrator->isglobaladmin
                    ? Domain::query()->orderBy('domain')->pluck('domain')->map(strval(...))->all()
                    : ($this->grantsFor([(string) $administrator->getKey()])[(string) $administrator->getKey()] ?? []),
                Actor::current(),
                $this->globalAdminCount(),
            ),
            'domains' => $this->domainOptions(),
            'candidates' => [],
        ]);
    }

    /**
     * Full demotion: the grants go and `isadmin` goes with them (BR-16). BR-A01
     * is checked inside the action's transaction and refuses this when the
     * account is the last global admin.
     */
    public function destroy(string $address, DemoteAdministratorAction $demoteAdministrator): RedirectResponse
    {
        $administrator = $this->administrator($address);

        $this->authorize('demote', [DomainAdmin::class, $administrator]);

        $demoteAdministrator->handle($administrator);

        return to_route('admins.index')->with('status', __('Administrator demoted. The mail account itself is untouched.'));
    }

    public function storeGlobal(string $address, PromoteToGlobalAdminAction $promoteToGlobalAdmin): RedirectResponse
    {
        $administrator = $this->administrator($address);

        $this->authorize('promoteToGlobal', [DomainAdmin::class, $administrator]);

        $promoteToGlobalAdmin->handle($administrator);

        return back()->with('status', __('Promoted to global administrator.'));
    }

    /**
     * BR-A02 refuses this for the actor's own account, in the policy. BR-A01
     * refuses it for the last global admin, inside the transaction.
     */
    public function destroyGlobal(string $address, DemoteAdministratorAction $demoteAdministrator): RedirectResponse
    {
        $administrator = $this->administrator($address);

        $this->authorize('revokeGlobal', [DomainAdmin::class, $administrator]);

        $demoteAdministrator->revokeGlobal($administrator);

        return back()->with('status', __('Global administrator flag revoked.'));
    }

    public function storeDomain(
        StoreDomainAdminRequest $request,
        string $address,
        GrantDomainAction $grantDomain,
    ): RedirectResponse {
        $grantDomain->handle($this->administrator($address), [(string) $request->validated('domain')]);

        return back()->with('status', __('Domain assigned.'));
    }

    /**
     * Removes that one grant and never the `'ALL'` sentinel (AC-11).
     */
    public function destroyDomain(
        string $address,
        string $domain,
        RevokeDomainAction $revokeDomain,
    ): RedirectResponse {
        $administrator = $this->administrator($address);

        $this->authorize('manage', [DomainAdmin::class, $administrator]);

        $revokeDomain->handle($administrator, mb_strtolower(trim($domain)));

        return back()->with('status', __('Domain removed.'));
    }

    /**
     * The administrator this request names.
     *
     * Resolved without the domain scope, deliberately and visibly
     * ({@see MailModel::scopeWithoutDomainScope()}): an
     * administrator's own mailbox may sit in a domain nobody administers, and
     * scoping the look-up would turn every refusal into a 404 that hides
     * whether the account exists. Authorization is the policy's job, and it
     * runs on the resolved record — which is what makes AC-14's 403 a 403.
     *
     * The address is lower-cased here for the same reason it is lower-cased in
     * a request body: BR-08 applies to look-ups as well as writes.
     */
    private function administrator(string $address): Mailbox
    {
        return Mailbox::withoutDomainScope()
            ->whereKey(mb_strtolower(trim($address)))
            ->firstOrFail();
    }

    /**
     * The accounts the listing shows.
     *
     * A global admin sees every account carrying either flag. A domain admin
     * sees the accounts holding a live grant over one of their domains — the
     * scope rule applied to `domain_admins` rather than to the mailboxes, which
     * is what the Actors table in the feature document describes. The grants
     * query is scoped by {@see DomainScope} on
     * `domain_admins.domain`, which also excludes the `'ALL'` sentinel by
     * construction.
     *
     * @return LengthAwarePaginator<int, Mailbox>
     */
    private function administrators(Actor $actor, string $search): LengthAwarePaginator
    {
        $query = Mailbox::withoutDomainScope()
            ->when($search !== '', fn ($builder) => $builder->where(function ($builder) use ($search): void {
                $builder->where('username', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            }));

        if ($actor->isGlobalAdmin()) {
            $query->where(function ($builder): void {
                // Integer binds, never PHP booleans (matrix D4).
                $builder->where('isadmin', 1)->orWhere('isglobaladmin', 1);
            });
        } else {
            $query->whereIn('username', $this->addressesGrantedOnActorsDomains());
        }

        return $query->orderBy('username')->paginate(25)->withQueryString();
    }

    /**
     * @return list<string>
     */
    private function addressesGrantedOnActorsDomains(): array
    {
        return DomainAdmin::query()
            ->where('active', 1)
            ->where('expired', '>', now())
            ->pluck('username')
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The real domains each of these addresses administers, keyed by address.
     *
     * The `'ALL'` sentinel is excluded (BR-05): it names no row in `domain`, and
     * a global admin's reach is resolved from the flag instead. A grant that is
     * inactive or expired confers nothing and is left out (BR-10), exactly as
     * {@see Actor::administeredDomains()} does.
     *
     * @param  list<string>  $addresses
     * @return array<string, list<string>>
     */
    private function grantsFor(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $grants = [];

        $rows = DB::connection('vmail')->table('domain_admins')
            ->whereIn('username', $addresses)
            ->where('domain', '<>', DomainAdmin::ALL_DOMAINS)
            ->where('active', 1)
            ->where('expired', '>', now())
            ->orderBy('domain')
            ->get(['username', 'domain']);

        foreach ($rows as $row) {
            $grants[(string) $row->username][] = (string) $row->domain;
        }

        return $grants;
    }

    /**
     * BR-03: `mailbox.isglobaladmin` is the authoritative count, not the
     * `'ALL'` rows, which can drift for reasons that have nothing to do with
     * Mailward (BR-19). Sent to the page so the UI can hide a control BR-A01
     * would refuse — the server checks it again inside the transaction.
     */
    private function globalAdminCount(): int
    {
        return Mailbox::withoutDomainScope()->where('isglobaladmin', 1)->count();
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, mixed>
     */
    private function present(Mailbox $administrator, array $domains, Actor $actor, int $globalAdminCount): array
    {
        $address = (string) $administrator->getKey();
        $isSelf = $actor->address() === $address;
        $manages = $actor->isGlobalAdmin();

        return [
            'address' => $address,
            'name' => $administrator->name,
            'isadmin' => $administrator->isadmin,
            'isglobaladmin' => $administrator->isglobaladmin,
            'domains' => $domains,

            /*
             * UX only. A hidden button is not a security boundary
             * (`docs/policies/authorization.md` §4) — every one of these is
             * decided again on the server.
             */
            'can' => [
                'manage' => $manages,
                'promoteToGlobal' => $manages && ! $administrator->isglobaladmin,
                'revokeGlobal' => $manages && ! $isSelf && $administrator->isglobaladmin && $globalAdminCount > 1,
                'demote' => $manages && ! $isSelf && ! ($administrator->isglobaladmin && $globalAdminCount <= 1),
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function domainOptions(): array
    {
        return Domain::query()->orderBy('domain')->pluck('domain')
            ->map(fn (string $domain): array => ['value' => $domain, 'label' => $domain])
            ->all();
    }

    /**
     * Accounts that could be promoted. Scoped, so the picker cannot offer an
     * account the server would then refuse to act on.
     *
     * @return list<array{value: string, label: string}>
     */
    private function promotableAccounts(): array
    {
        return Mailbox::query()
            ->where('isadmin', 0)
            ->where('isglobaladmin', 0)
            ->orderBy('username')
            ->limit(500)
            ->pluck('name', 'username')
            ->map(fn (?string $name, string $address): array => [
                'value' => $address,
                'label' => $name === null || $name === '' ? $address : "{$name} ({$address})",
            ])
            ->values()
            ->all();
    }
}
