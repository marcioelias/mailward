<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Mailboxes\AddMailboxAliasAction;
use App\Actions\Mailboxes\AddMailboxForwardingAction;
use App\Actions\Mailboxes\RemoveMailboxAliasAction;
use App\Actions\Mailboxes\RemoveMailboxForwardingAction;
use App\Http\Requests\Mailboxes\StoreMailboxAliasRequest;
use App\Http\Requests\Mailboxes\StoreMailboxForwardingRequest;
use App\Models\Mail\Forwarding;
use App\Models\Mail\Mailbox;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user aliases and forwardings — `docs/features/mailbox-aliases-forwardings.md`.
 *
 * **Two concepts, one table.** `forwardings` multiplexes four unrelated
 * purposes behind flag columns, and `docs/02-domain.md` §5 requires them to be
 * exposed as distinct concepts rather than as one "forwardings" screen
 * (BR-01). They share a screen here because they share an owner — both are the
 * contents of one account — but they are two labelled sections with separate
 * endpoints, which is what the feature document permits.
 *
 * The other two purposes are not visible here at all: `is_list` belongs to
 * standalone alias accounts and `is_maillist` to mlmmj lists, and neither is
 * listed, edited or deleted by this controller (BR-05).
 *
 * Every action is create or delete. `active` is written 1 on create and never
 * updated, and no control offers to toggle it (BR-19).
 */
final class MailboxRoutingController extends Controller
{
    public function index(Mailbox $mailbox): Response
    {
        $this->authorize('update', $mailbox);

        return Inertia::render('Mailboxes/Routing', [
            'mailbox' => [
                'username' => $mailbox->getKey(),
                'name' => $mailbox->name,
                'domain' => $mailbox->domain,
            ],
            'aliases' => $this->aliases($mailbox),
            'forwardings' => $this->forwardings($mailbox),
        ]);
    }

    public function storeAlias(
        StoreMailboxAliasRequest $request,
        Mailbox $mailbox,
        AddMailboxAliasAction $addAlias,
    ): RedirectResponse {
        $addAlias->handle($mailbox, (string) $request->validated('address'));

        return back()->with('status', __('Alias added.'));
    }

    public function destroyAlias(
        Mailbox $mailbox,
        string $alias,
        RemoveMailboxAliasAction $removeAlias,
    ): RedirectResponse {
        $this->authorize('update', $mailbox);

        $removeAlias->handle($mailbox, $this->aliasRow($mailbox, $alias));

        return back()->with('status', __('Alias removed.'));
    }

    public function storeForwarding(
        StoreMailboxForwardingRequest $request,
        Mailbox $mailbox,
        AddMailboxForwardingAction $addForwarding,
    ): RedirectResponse {
        $addForwarding->handle($mailbox, (string) $request->validated('forwarding'));

        return back()->with('status', __('Forwarding added.'));
    }

    public function destroyForwarding(
        Mailbox $mailbox,
        string $forwarding,
        RemoveMailboxForwardingAction $removeForwarding,
    ): RedirectResponse {
        $this->authorize('update', $mailbox);

        $removeForwarding->handle($mailbox, $this->forwardingRow($mailbox, $forwarding));

        return back()->with('status', __('Forwarding removed.'));
    }

    /**
     * The account's `is_alias` rows.
     *
     * Anchored on `forwarding`, which holds the owning mailbox for these rows
     * (see AddMailboxAliasAction on OQ-A1) and is indexed on both drivers. It is
     * never the flag that carries the query: MySQL has no index on
     * `is_forwarding` where PostgreSQL does, and the flag is a discriminator,
     * not a selector (BR-09, matrix D15).
     *
     * @return list<array{id: int, address: string, active: bool}>
     */
    private function aliases(Mailbox $mailbox): array
    {
        return Forwarding::query()
            ->where('forwarding', (string) $mailbox->getKey())
            ->where('is_alias', 1)
            ->orderBy('address')
            ->get()
            ->map(fn (Forwarding $row): array => [
                'id' => $row->id,
                'address' => $row->address,

                /*
                 * Shown, not offered. A row written outside Mailward with
                 * `active = 0` is listed like any other and no control enables
                 * it — Mailward does not know the column means anything on
                 * these rows (BR-19, probe E6).
                 */
                'active' => $row->active,
            ])
            ->all();
    }

    /**
     * The account's `is_forwarding` rows, **without the self-referencing one**.
     *
     * That row — `address = forwarding =` the account's own address — is an
     * invariant of the mailbox rather than a forwarding anybody chose, and an
     * account without it receives no mail. It is excluded here so it can never
     * be offered a Remove button, and refused again in
     * RemoveMailboxForwardingAction so hiding it is not the only defence
     * (BR-06, AC-03).
     *
     * @return list<array{id: int, forwarding: string, active: bool}>
     */
    private function forwardings(Mailbox $mailbox): array
    {
        $address = (string) $mailbox->getKey();

        return Forwarding::query()
            ->where('address', $address)
            ->where('is_forwarding', 1)
            ->where('forwarding', '<>', $address)
            ->orderBy('forwarding')
            ->get()
            ->map(fn (Forwarding $row): array => [
                'id' => $row->id,
                'forwarding' => $row->forwarding,
                'active' => $row->active,
            ])
            ->all();
    }

    /**
     * BR-10 — a row is addressed by its `id`, but the two drivers generate that
     * id from different sequence types, so nothing assumes a comparable range
     * and every lookup is additionally constrained by the owning account's
     * address. An id belonging to another mailbox is not found here.
     */
    private function aliasRow(Mailbox $mailbox, string $id): Forwarding
    {
        $row = Forwarding::query()
            ->where('forwarding', (string) $mailbox->getKey())
            ->where('is_alias', 1)
            ->whereKey($id)
            ->first();

        if (! $row instanceof Forwarding) {
            abort(404);
        }

        return $row;
    }

    /**
     * BR-10, and the self-referencing row is deliberately still reachable here:
     * refusing it is RemoveMailboxForwardingAction's job, and a 404 would say
     * the row does not exist when the truth is that it may not be removed.
     */
    private function forwardingRow(Mailbox $mailbox, string $id): Forwarding
    {
        $row = Forwarding::query()
            ->where('address', (string) $mailbox->getKey())
            ->where('is_forwarding', 1)
            ->whereKey($id)
            ->first();

        if (! $row instanceof Forwarding) {
            abort(404);
        }

        return $row;
    }
}
