<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\Mail\Alias;
use App\Models\Mail\Domain;
use App\Models\Mail\Mailbox;
use App\Models\Mail\Scopes\DomainScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every figure the dashboard shows, for the domains the acting administrator
 * may see (docs/features/dashboard.md).
 *
 * Three properties hold everywhere in this class, and each of them is a rule
 * rather than a preference:
 *
 * - **Scoped in the query.** Every statement below is built from a model
 *   carrying {@see DomainScope}, so a domain admin's
 *   totals are restricted by the server before a row is read, never by
 *   filtering afterwards (BR-01, and `docs/policies/authorization.md` §3). A
 *   global admin is widened by the scope itself, which returns without
 *   restricting — no call site here removes it.
 * - **Aggregated, never hydrated.** Each figure is one aggregate query, and
 *   every builder is dropped to `toBase()` so not a single `Mailbox` instance
 *   is constructed. With thousands of accounts, counting a hydrated collection
 *   is both slow and one pagination bug from leaking another domain's rows
 *   (BR-09).
 * - **`used_quota.domain` is never read.** Per-domain usage is correlated
 *   through `used_quota.username` against `mailbox`. That column is filled by
 *   a trigger on MySQL and by nothing at all on PostgreSQL, so grouping on it
 *   returns correct totals on one driver and an empty-string bucket on the
 *   other — wrong numbers, no error, on the figure an administrator trusts
 *   most (BR-03, matrix D14).
 *
 * Units are not interchangeable and are named in every key: `mailbox.quota` is
 * **mebibytes** and `used_quota.bytes` is **bytes**. Reading either as the
 * other is wrong by a factor of 1,048,576 and both readings look plausible
 * (OQ-DASH-01).
 */
final class GatherDashboardFiguresAction
{
    /**
     * @return array{
     *     domains: array{total: int, inactive: int},
     *     mailboxes: array{total: int, inactive: int},
     *     aliases: array{total: int, inactive: int},
     *     quota: array{allocatedMib: int, usedBytes: int},
     *     perDomain: list<array{domain: string, mailboxes: int, allocatedMib: int, usedBytes: int}>,
     *     atLimit: list<array{domain: string, mailboxes: array{count: int, limit: int}, aliases: array{count: int, limit: int}, reasons: list<string>}>,
     *     dormancy: array{thresholdDays: int, dormant: int, neverLoggedIn: int, unknown: int, degraded: bool}
     * }
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();

        $perDomain = $this->perDomainUsage();

        return [
            'domains' => $this->countWithInactive(Domain::query(), $now),
            'mailboxes' => $this->countWithInactive(Mailbox::query(), $now),
            'aliases' => $this->countWithInactive(Alias::query(), $now),
            'quota' => [
                'allocatedMib' => array_sum(array_column($perDomain, 'allocatedMib')),
                'usedBytes' => array_sum(array_column($perDomain, 'usedBytes')),
            ],
            'perDomain' => $perDomain,
            'atLimit' => $this->domainsAtTheirLimit($perDomain),
            'dormancy' => $this->dormancy($now),
        ];
    }

    /**
     * The headline count is every row in scope, and the breakdown beside it
     * counts the rows that are not live — `active = 0` **or** expired, a row
     * that is both contributing once rather than twice (BR-19, Q21=A).
     *
     * Counting only live rows was refused: the figure would then disagree with
     * the total every listing in the product reports.
     *
     * `active` is compared against the integer and expiry against `now()`,
     * never a sentinel constant: PostgreSQL rejects a PHP boolean against
     * INT2, and the two drivers ship different "never expires" values
     * (BR-14, matrix D4 and D2).
     *
     * @param  Builder<covariant Model>  $query
     * @return array{total: int, inactive: int}
     */
    private function countWithInactive(Builder $query, CarbonImmutable $now): array
    {
        $row = $query
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when active = ? or expired <= ? then 1 else 0 end) as inactive', [0, $now])
            ->toBase()
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
        ];
    }

    /**
     * Allocated quota and used quota per domain, in one aggregate query.
     *
     * The correlation is `used_quota.username = mailbox.username` and nothing
     * else. Grouping is on `mailbox.domain`, which every driver fills, rather
     * than on `used_quota.domain`, which only MySQL does (BR-03, matrix D14).
     *
     * The join is a LEFT join because a mailbox Dovecot has not yet written a
     * usage row for still counts, with usage of zero. Its absence is a lag in
     * a table Mailward does not own, never a reason to drop the account from
     * the figures (BR-06).
     *
     * @return list<array{domain: string, mailboxes: int, allocatedMib: int, usedBytes: int}>
     */
    private function perDomainUsage(): array
    {
        $rows = Mailbox::query()
            ->leftJoin('used_quota', 'used_quota.username', '=', 'mailbox.username')
            ->select('mailbox.domain')
            ->selectRaw('count(*) as mailboxes')
            ->selectRaw('coalesce(sum(mailbox.quota), 0) as allocated_mib')
            ->selectRaw('coalesce(sum(used_quota.bytes), 0) as used_bytes')
            ->groupBy('mailbox.domain')
            ->orderBy('mailbox.domain')
            ->toBase()
            ->get();

        return $rows->map(fn (object $row): array => [
            'domain' => (string) $row->domain,
            'mailboxes' => (int) $row->mailboxes,
            // Mebibytes. `mailbox.quota` is not a byte count.
            'allocatedMib' => (int) $row->allocated_mib,
            // Bytes. `used_quota.bytes` is not a mebibyte count.
            'usedBytes' => (int) $row->used_bytes,
        ])->values()->all();
    }

    /**
     * Domains in scope that have reached a limit they actually have.
     *
     * `0` means unlimited rather than "none allowed", so a domain with a zero
     * limit never appears here however many accounts it holds (BR-07). A
     * non-zero limit is reached when the count is greater than or equal to it
     * (BR-08).
     *
     * The alias limit is compared against **standalone `alias` rows only**.
     * Per-account aliases live in `forwardings` and alias domains in
     * `alias_domain`, and neither is bounded by any limit, so neither is
     * counted here — a decision, not an omission (BR-18).
     *
     * `domain.maillists` is deliberately absent: mailing lists are unmodelled
     * in v1, so the count that would be compared against it does not exist.
     * So is `domain.maxquota`, which bounds an individual mailbox rather than
     * the domain's total (Out of Scope).
     *
     * @param  list<array{domain: string, mailboxes: int, allocatedMib: int, usedBytes: int}>  $perDomain
     * @return list<array{domain: string, mailboxes: array{count: int, limit: int}, aliases: array{count: int, limit: int}, reasons: list<string>}>
     */
    private function domainsAtTheirLimit(array $perDomain): array
    {
        $mailboxCounts = array_column($perDomain, 'mailboxes', 'domain');

        $aliasCounts = Alias::query()
            ->select('alias.domain')
            ->selectRaw('count(*) as total')
            ->groupBy('alias.domain')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->domain => (int) $row->total])
            ->all();

        $limits = Domain::query()
            ->select('domain', 'mailboxes', 'aliases')
            ->orderBy('domain')
            ->toBase()
            ->get();

        $reached = [];

        foreach ($limits as $limit) {
            $name = (string) $limit->domain;

            $mailboxes = ['count' => (int) ($mailboxCounts[$name] ?? 0), 'limit' => (int) $limit->mailboxes];
            $aliases = ['count' => (int) ($aliasCounts[$name] ?? 0), 'limit' => (int) $limit->aliases];

            $reasons = [];

            if ($this->isAtLimit($mailboxes['count'], $mailboxes['limit'])) {
                $reasons[] = 'mailboxes';
            }

            if ($this->isAtLimit($aliases['count'], $aliases['limit'])) {
                $reasons[] = 'aliases';
            }

            if ($reasons !== []) {
                $reached[] = ['domain' => $name, 'mailboxes' => $mailboxes, 'aliases' => $aliases, 'reasons' => $reasons];
            }
        }

        return $reached;
    }

    private function isAtLimit(int $count, int $limit): bool
    {
        return $limit > 0 && $count >= $limit;
    }

    /**
     * How many accounts in scope nobody has read lately.
     *
     * **Last login is the greater of `imap` and `pop3`. `lda` is excluded and
     * is not selected by any statement here** — it records a *delivery* into
     * the account rather than a person connecting to it, so an account nobody
     * has opened for two years looks active under it for as long as anything
     * still sends mail there, which inverts the figure this screen exists to
     * give (BR-20).
     *
     * The columns are 32-bit on MySQL and overflow in January 2038, so a
     * negative, zero or future value is unreliable and is dropped before the
     * two are compared — an unreliable value can therefore never win the
     * comparison (BR-12, matrix D13). Three outcomes follow, and they are
     * three different answers rather than three ways of saying zero:
     *
     * - **dormant** — a reliable login older than the threshold;
     * - **never logged in** — no `last_login` row, or both columns NULL
     *   (BR-13);
     * - **unknown** — a row exists but every value in it is unreliable. These
     *   are counted separately and reported as `degraded`, never folded into
     *   either of the other two (BR-20, States).
     *
     * `last_login` is correlated on `username` explicitly and is never reached
     * through `find()`: its primary key is `(username)` on MySQL and
     * `(username, domain)` on PostgreSQL (BR-11, matrix D12). That same
     * divergence means `username` is not declared unique on PostgreSQL, so the
     * table is folded to one row per address before the join rather than
     * after — a duplicate would otherwise count one account twice.
     *
     * @return array{thresholdDays: int, dormant: int, neverLoggedIn: int, unknown: int, degraded: bool}
     */
    private function dormancy(CarbonImmutable $now): array
    {
        $days = max(1, (int) config('mailward.dashboard.dormant_after_days', 90));

        $nowTimestamp = $now->getTimestamp();
        $threshold = $now->subDays($days)->getTimestamp();

        $lastLogin = DB::connection('vmail')->table('last_login')
            ->select('username')
            ->selectRaw('max(imap) as imap')
            ->selectRaw('max(pop3) as pop3')
            ->groupBy('username');

        /*
         * A NULL column fails the `> 0` test on both drivers, so absent and
         * unreliable collapse to NULL here and are told apart by never_seen.
         */
        $accounts = Mailbox::query()
            ->leftJoinSub($lastLogin, 'll', 'll.username', '=', 'mailbox.username')
            ->selectRaw('case when ll.imap > 0 and ll.imap <= ? then ll.imap end as imap', [$nowTimestamp])
            ->selectRaw('case when ll.pop3 > 0 and ll.pop3 <= ? then ll.pop3 end as pop3', [$nowTimestamp])
            ->selectRaw('case when ll.imap is null and ll.pop3 is null then 1 else 0 end as never_seen')
            ->toBase();

        $row = DB::connection('vmail')->query()
            ->fromSub($accounts, 'accounts')
            ->selectRaw('sum(case when never_seen = 1 then 1 else 0 end) as never_logged_in')
            ->selectRaw('sum(case when never_seen = 0 and imap is null and pop3 is null then 1 else 0 end) as unknown')
            ->selectRaw(
                'sum(case when (imap is not null or pop3 is not null)'
                .' and (case when imap is null then pop3 when pop3 is null then imap'
                .' when imap > pop3 then imap else pop3 end) < ? then 1 else 0 end) as dormant',
                [$threshold],
            )
            ->first();

        $unknown = (int) ($row->unknown ?? 0);

        return [
            'thresholdDays' => $days,
            'dormant' => (int) ($row->dormant ?? 0),
            'neverLoggedIn' => (int) ($row->never_logged_in ?? 0),
            'unknown' => $unknown,
            // Per figure, never for the page: an unreliable timestamp
            // suppresses this figure's confidence and leaves the counts alone.
            'degraded' => $unknown > 0,
        ];
    }
}
