<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Mailboxes\CreateMailboxAction;
use App\Actions\Mailboxes\DeleteMailboxAction;
use App\Actions\Mailboxes\UpdateMailboxAction;
use App\Http\Requests\Mailboxes\StoreMailboxRequest;
use App\Http\Requests\Mailboxes\UpdateMailboxPasswordRequest;
use App\Http\Requests\Mailboxes\UpdateMailboxRequest;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Support\PasswordScheme\SchemeRegistry;
use App\Support\Quota\DomainAllowance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class MailboxController extends Controller
{
    /**
     * The toggles the form exposes. The rest keep whatever iRedMail set and
     * are never written — `enablelib-storage`, `enablequota-status` and
     * `enableindexer-worker` are Dovecot internals no administrator should be
     * switching off (`docs/features/mailboxes.md` BR-30).
     *
     * @var array<string, string>
     */
    private const SERVICES = [
        'enablesmtp' => 'SMTP',
        'enablepop3' => 'POP3',
        'enableimap' => 'IMAP',
        'enablelda' => 'Delivery (LDA)',
        'enablelmtp' => 'Delivery (LMTP)',
        'enablesieve' => 'Sieve',
        'enablemanagesieve' => 'ManageSieve',
        'enablesogo' => 'SOGo',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Mailbox::class);

        $search = trim((string) $request->query('search', ''));
        $domain = trim((string) $request->query('domain', ''));

        $mailboxes = Mailbox::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('username', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            }))
            ->when($domain !== '', fn ($query) => $query->where('domain', $domain))
            ->orderBy('username')
            ->paginate(25)
            ->withQueryString();

        /*
         * Usage is correlated through `used_quota.username`, never grouped on
         * `used_quota.domain` — that column is filled by a trigger on MySQL and
         * by nothing on PostgreSQL (BR-14, matrix D14).
         */
        $usage = $this->usageFor($mailboxes->pluck('username')->all());

        return Inertia::render('Mailboxes/Index', [
            'mailboxes' => $mailboxes->through(fn (Mailbox $mailbox): array => [
                'username' => $mailbox->getKey(),
                'name' => $mailbox->name,
                'domain' => $mailbox->domain,
                'active' => $mailbox->active,
                'isadmin' => $mailbox->isadmin,
                'isglobaladmin' => $mailbox->isglobaladmin,
                'quota' => $mailbox->quota,
                'usedBytes' => $usage[(string) $mailbox->getKey()] ?? 0,
                'weakness' => app(SchemeRegistry::class)->weakness((string) $mailbox->getAttribute('password')),
            ]),
            'filters' => ['search' => $search, 'domain' => $domain],
            'domains' => $this->domainOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Mailbox::class);

        $domain = trim((string) $request->query('domain', ''));

        return Inertia::render('Mailboxes/Form', [
            'mailbox' => null,
            'domains' => $this->domainOptions(),
            'services' => $this->serviceOptions(),
            'allowance' => $domain === '' ? null : $this->allowanceFor($domain),
        ]);
    }

    public function store(StoreMailboxRequest $request, CreateMailboxAction $createMailbox): RedirectResponse
    {
        $mailbox = $createMailbox->handle($this->withServices($request->validated(), $request));

        return to_route('mailboxes.index', ['domain' => $mailbox->domain])
            ->with('status', __('Mailbox created.'));
    }

    public function edit(Mailbox $mailbox): Response
    {
        $this->authorize('update', $mailbox);

        $usage = $this->usageFor([(string) $mailbox->getKey()]);

        return Inertia::render('Mailboxes/Form', [
            'mailbox' => [
                'username' => $mailbox->getKey(),
                'name' => $mailbox->name,
                'domain' => $mailbox->domain,
                'quota' => $mailbox->quota,
                'active' => $mailbox->active,
                'services' => $this->currentServices($mailbox),
                'usedBytes' => $usage[(string) $mailbox->getKey()] ?? 0,
                'lastLogin' => $this->lastLoginFor((string) $mailbox->getKey()),
                'weakness' => app(SchemeRegistry::class)->weakness((string) $mailbox->getAttribute('password')),
            ],
            'domains' => $this->domainOptions(),
            'services' => $this->serviceOptions(),
            'allowance' => $this->allowanceFor((string) $mailbox->domain, (string) $mailbox->getKey()),
        ]);
    }

    public function update(
        UpdateMailboxRequest $request,
        Mailbox $mailbox,
        UpdateMailboxAction $updateMailbox,
    ): RedirectResponse {
        $updateMailbox->handle($mailbox, $this->withServices($request->validated(), $request));

        return to_route('mailboxes.index')->with('status', __('Mailbox updated.'));
    }

    public function updatePassword(
        UpdateMailboxPasswordRequest $request,
        Mailbox $mailbox,
        UpdateMailboxAction $updateMailbox,
    ): RedirectResponse {
        $updateMailbox->setPassword($mailbox, (string) $request->validated('password'));

        return back()->with('status', __('Mail password changed. It now applies to IMAP, SMTP and webmail.'));
    }

    public function setActive(
        Request $request,
        Mailbox $mailbox,
        UpdateMailboxAction $updateMailbox,
    ): RedirectResponse {
        $this->authorize('update', $mailbox);

        $active = $request->boolean('active');

        $updateMailbox->handle($mailbox, ['active' => $active]);

        return back()->with('status', $active ? __('Mailbox enabled.') : __('Mailbox disabled.'));
    }

    public function destroy(Mailbox $mailbox, DeleteMailboxAction $deleteMailbox): RedirectResponse
    {
        $this->authorize('delete', $mailbox);

        $deleteMailbox->handle($mailbox);

        return to_route('mailboxes.index')->with('status', __('Mailbox deleted. iRedMail removes the mail files on its own schedule.'));
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, int>
     */
    private function usageFor(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        return DB::connection('vmail')->table('used_quota')
            ->whereIn('username', $addresses)
            ->pluck('bytes', 'username')
            ->map(fn ($bytes): int => (int) $bytes)
            ->all();
    }

    /**
     * `last_login` is read-only, and its primary key differs per driver — so
     * it is looked up with an explicit where, never `find()` (BR-15, D12).
     *
     * @return array{imap: ?int, pop3: ?int}
     */
    private function lastLoginFor(string $address): array
    {
        $row = DB::connection('vmail')->table('last_login')->where('username', $address)->first();

        /*
         * `lda` is excluded on purpose: it records a delivery, not a login, so
         * including it makes an account nobody has ever read look active
         * (Q22).
         */
        return [
            'imap' => $row?->imap === null ? null : (int) $row->imap,
            'pop3' => $row?->pop3 === null ? null : (int) $row->pop3,
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
     * @return list<array{key: string, label: string}>
     */
    private function serviceOptions(): array
    {
        return collect(self::SERVICES)
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function currentServices(Mailbox $mailbox): array
    {
        return collect(self::SERVICES)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => (bool) $mailbox->getAttribute($key)])
            ->all();
    }

    /**
     * Only the curated keys are ever written, whatever the request contains.
     *
     * `enablesogo` gates the three SOGo columns, which are a character type
     * holding 'y'/'n' rather than integers — the cast handles the difference,
     * but they must move together or SOGo sees a half-configured account
     * (BR-30).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withServices(array $validated, Request $request): array
    {
        $submitted = (array) $request->input('services', []);

        unset($validated['services']);

        foreach (array_keys(self::SERVICES) as $key) {
            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $enabled = filter_var($submitted[$key], FILTER_VALIDATE_BOOLEAN);
            $validated[$key] = $enabled;

            if ($key === 'enablesogo') {
                $validated['enablesogowebmail'] = $enabled;
                $validated['enablesogocalendar'] = $enabled;
                $validated['enablesogoactivesync'] = $enabled;
            }
        }

        return $validated;
    }

    /**
     * @return array{mailboxes: ?int, quotaMib: ?int}
     */
    private function allowanceFor(string $domain, ?string $excluding = null): array
    {
        return [
            'mailboxes' => DomainAllowance::mailboxes($domain),
            'quotaMib' => DomainAllowance::quota($domain, $excluding),
        ];
    }
}
