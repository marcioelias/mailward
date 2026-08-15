# Dashboard

Status: draft

Scope line: `docs/00-overview.md` §5, v1 — "Dashboard: account counts, quota
totals, domains at their limit", read together with "Quota usage, per account
and per domain" and "Last login per account".

Sources: `docs/01-architecture.md` §3, §7, `docs/02-domain.md` §1.2, §1.3, §2,
§6, §9, §10, `docs/policies/authorization.md` §1, §2, §3, §4, §5,
`docs/reference/schema-type-matrix.md` (D2, D4, D7, D12, D13, D14),
`docs/decisions/0004-inertia-vue.md`.

---

## Purpose

One screen that answers, for the domains the actor administers: how many
accounts exist, how much quota is allocated and how much of it is used, which
domains have reached a per-domain limit, and which accounts appear dormant.

Every figure here is a number an administrator will act on without checking it
against the database. That is the whole risk of this feature: a wrong aggregate
produces no error and looks exactly like a right one.

## Actors

| Actor | Sees |
|---|---|
| Global admin | figures aggregated over every domain (`policies/authorization.md` §1) |
| Domain admin | figures aggregated over their `domain_admins` domains only |
| Mail user | no access — cannot sign in (`policies/authorization.md` §1) |

`docs/policies/authorization.md` applies in full and is not restated here. This
feature adds no role and relaxes no scope.

## Business Rules

- **BR-01** — Every figure is scoped by `policies/authorization.md` §2, and the
  scope is applied **in the query**, never as a filter over loaded results
  (§3 of that policy). A domain admin's dashboard must never aggregate over
  domains they cannot see, at any point in the computation.
- **BR-02** — For a global admin the aggregate covers every domain; the scope is
  removed explicitly and visibly at the call site, never by omission
  (`policies/authorization.md` §3).
- **BR-03** — Per-domain quota usage is derived by correlating
  `used_quota.username` against `mailbox`, on **both** drivers.
  **`used_quota.domain` is never read.** It is filled by a trigger on MySQL and
  by nothing on PostgreSQL, so grouping on it yields correct totals on one
  driver and an empty-string bucket on the other — wrong numbers, no error
  (`02-domain.md` §9, matrix D14).
- **BR-04** — `used_quota` is read-only. This feature issues no INSERT, UPDATE
  or DELETE against it (`02-domain.md` §9).
- **BR-05** — The quota *limit* is `mailbox.quota`; the quota *usage* is
  `used_quota.bytes` (`02-domain.md` §9). `domain.quota` is historical and
  unused and is surfaced nowhere (`02-domain.md` §2).
- **BR-06** — A mailbox with no `used_quota` row still counts in every account
  figure; its usage is zero-or-unknown, never a reason to exclude the account
  (`02-domain.md` §9 — the table is Dovecot's and may lag).
- **BR-07** — The per-domain limits are `domain.aliases`, `domain.mailboxes` and
  `domain.maillists`. **`0` means unlimited, not "zero allowed"**, and is
  displayed as unlimited (`02-domain.md` §2).
- **BR-08** — A domain is "at its limit" when a limit is non-zero and the
  current count for that limit is greater than or equal to it. `02-domain.md` §2
  requires the dashboard to surface a limit that has been reached.
- **BR-09** — Aggregates are computed by aggregate queries. The dashboard never
  hydrates a collection in order to count it: with thousands of accounts that is
  both slow and one pagination bug away from leaking another domain's data
  (`policies/authorization.md` §3).
- **BR-10** — No query joins across database connections, and no figure is
  produced by a transaction spanning both databases. Figures that need both
  sides are correlated in PHP (`01-architecture.md` §3).
- **BR-11** — `last_login` is read-only and is looked up with an explicit
  `where('username', …)`, never with `find()`: its primary key is `(username)`
  on MySQL and `(username, domain)` on PostgreSQL (`02-domain.md` §10,
  matrix D12).
- **BR-12** — `imap`, `pop3` and `lda` are 32-bit on MySQL and overflow in 2038.
  A negative, zero or future value is treated as unreliable and reported as
  unknown; it is never rendered as a date and never used to rank dormancy
  (`02-domain.md` §10, matrix D13). Mailward never computes or writes into these
  columns.
- **BR-13** — An absent `last_login` row, or a NULL protocol column, means no
  login of that kind was ever recorded. It is reported as never, and is
  distinguishable from an unreliable value under BR-12 (matrix §8: the three
  columns are nullable on both drivers).
- **BR-14** — Any figure that filters on `active` binds `1`/`0`, never
  `true`/`false` (`02-domain.md` §1.3, matrix D4); any figure that filters on
  expiry evaluates `expired > now()` and never compares against a sentinel
  constant (`02-domain.md` §1.2, matrix D2).
- **BR-15** — An administrator with no administered domains sees an empty
  dashboard — every figure zero or empty — and not an error
  (`policies/authorization.md` §5, BR-A03).
- **BR-16** — Authorization props are passed to the frontend to show and hide
  controls only. The dashboard authorises on the server on every request,
  regardless of what the interface offered (`policies/authorization.md` §4).
- **BR-17** — The dashboard is read-only. It performs no write on any
  connection, and therefore produces no `audit_log` entry for a successful view
  (`policies/authorization.md` §7 records writes; an authorization failure
  against this route is still recorded).
- **BR-18** — The count compared against `domain.aliases` under BR-07 and BR-08
  is the number of **standalone alias accounts** in the domain — rows in
  `vmail.alias` — and nothing else (`docs/reference/decisions-needed.md` D2;
  `docs/features/domains.md` BR-18). Per-account aliases
  (`forwardings.is_alias`) and alias domains (`alias_domain`) are bounded by no
  limit, so they enter no figure on this screen and their absence from "domains
  at their limit" is a decision, not an omission. The alias limit is therefore
  fully computable, and "domains at their limit" covers `domain.aliases` and
  `domain.mailboxes`; `domain.maillists` stays excluded for the separate reason
  in Out of Scope.

## Data

**Read — `vmail`, all read-only in this feature:**

| Table | Columns | Use |
|---|---|---|
| `domain` | `domain`, `aliases`, `mailboxes`, `maillists`, `maxquota`, `active`, `expired` | limits (BR-07, BR-08), domain count |
| `mailbox` | `username`, `domain`, `quota`, `active`, `expired` | account count, allocated quota, correlation key for usage (BR-03) |
| `alias` | `address`, `domain`, `active` | standalone alias count, and the only population counted against `domain.aliases` (BR-18) |
| `used_quota` | `username`, `bytes`, `messages` — **never `domain`** | usage (BR-03, BR-04) |
| `last_login` | `username`, `imap`, `pop3`, `lda` | dormancy (BR-11, BR-12, BR-13) |
| `domain_admins` | `username`, `domain` | the actor's scope (`policies/authorization.md` §2) |

`domain.aliases`, `mailboxes` and `maillists` are 32-bit on MySQL and 64-bit on
PostgreSQL (matrix D7); they are read here and never written.

**Figures produced** (each within the actor's scope, BR-01):

| Figure | Definition |
|---|---|
| Domains | count of `domain` rows in scope |
| Mailboxes | count of `mailbox` rows in scope |
| Standalone aliases | count of `alias` rows in scope |
| Quota allocated | sum of `mailbox.quota` in scope, overall and per domain |
| Quota used | sum of `used_quota.bytes` for the mailboxes in scope, overall and per domain, correlated by `username` (BR-03) |
| Domains at their limit | domains in scope where a non-zero limit has been reached (BR-07, BR-08); the alias limit counts `alias` rows only (BR-18) |
| Dormant accounts | mailboxes in scope whose most recent recorded login is older than the dormancy threshold, excluding unreliable values (BR-12, BR-13) |

Whether the counts include inactive or expired rows is OQ-DASH-02; what
"most recent login" means across the three columns, and what the threshold is,
is OQ-DASH-04. The unit of `mailbox.quota` is OQ-DASH-01 and every quota figure
above depends on it.

**Mailward's own database**: nothing is read or written by this feature, and no
figure crosses the two databases in a single query (BR-10).

## Contracts

Inertia page only. No JSON API is exposed for the panel's own use
(`decisions/0004`), and this feature has no form endpoint.

| Method | Path | Page / result |
|---|---|---|
| GET | `/dashboard` | `Dashboard` — the figures above as props, already scoped (BR-01) |

Props carry the computed figures and the actor's permission flags for UX only
(BR-16). No prop carries a row belonging to a domain outside the actor's scope,
and no prop carries a raw `mailbox.password`, `settings` or `domain.quota`
value.

Error cases: an unauthenticated request is redirected to `/login`
(`docs/features/authentication.md` BR-18). An authenticated administrator with
no domains receives 200 and an empty dashboard, never 403 (BR-15). There is no
request parameter that widens the scope; a scope-widening attempt has no
contract to attach to (Out of Scope: drill-down and filters).

## States

The dashboard holds no domain state. It renders in one of three presentation
states, and the distinction is load-bearing:

```
empty        actor administers no domain, or the domains hold no accounts (BR-15)
populated    every figure computed
degraded     a figure could not be computed reliably and says so
```

- `degraded` is per figure, never for the whole page: an unreliable
  `last_login` value (BR-12) suppresses the dormancy figure, not the counts.
- A figure is never rendered as `0` when the truth is "not computable". Zero and
  unknown are different values on this screen.
- No transition is triggered by the user; the state is a function of the data at
  request time.

## Acceptance Criteria

- **AC-01** — Given a domain admin administering `a.com` only, and a fixture
  with mailboxes in `a.com` and `b.com`, when they open `/dashboard`, then every
  count, sum and list reflects `a.com` only, and the executed aggregate queries
  are themselves constrained by domain. *(BR-01, BR-09)*
- **AC-02** — Given a global admin on the same fixture, when they open
  `/dashboard`, then the figures cover both domains. *(BR-02)*
- **AC-03** — Given the same fixture loaded on MySQL and on PostgreSQL, when
  per-domain quota usage is computed, then both drivers return identical totals.
  *(BR-03)*
- **AC-04** — Given a fixture in which every `used_quota.domain` value is the
  empty string, when per-domain usage is computed, then the totals are correct
  and non-zero, and no executed query references `used_quota.domain`. *(BR-03)*
- **AC-05** — Given a rendered dashboard, when every executed statement is
  inspected, then none is an INSERT, UPDATE or DELETE on any connection, and
  none targets `used_quota` or `last_login` for writing. *(BR-04, BR-17)*
- **AC-06** — Given a mailbox with no `used_quota` row, when the figures are
  computed, then the mailbox is still counted and the domain's usage total is
  computed without it. *(BR-06)*
- **AC-07** — Given `domain.mailboxes = 0`, when the dashboard renders, then the
  limit is presented as unlimited and the domain does not appear in "domains at
  their limit", regardless of how many mailboxes it holds. *(BR-07)*
- **AC-08** — Given `domain.mailboxes = 5`, when the domain holds 5 mailboxes it
  appears in "domains at their limit"; when it holds 4 it does not. *(BR-08)*
- **AC-09** — Given any dashboard render, when the props are inspected, then no
  prop carries a `domain.quota` value. *(BR-05)*
- **AC-10** — Given a domain admin administering `a.com` only, when
  `b.com` has reached a limit, then `b.com` appears nowhere in their "domains at
  their limit" list. *(BR-01, BR-08)*
- **AC-11** — Given an authenticated administrator with zero `domain_admins`
  rows and no global flag, when they open `/dashboard`, then the response is 200
  and every figure is empty or zero, with no error. *(BR-15)*
- **AC-12** — Given a `last_login` row whose `imap` value is negative or in the
  future, when the dormancy figure is computed, then that value is reported as
  unknown, the page renders without an exception, and the account is not ranked
  by it. *(BR-12)*
- **AC-13** — Given a mailbox with no `last_login` row at all, when the dormancy
  figure is computed, then it is reported as never having logged in, and is
  distinguishable in the props from the unreliable case of AC-12. *(BR-13)*
- **AC-14** — Given the dashboard renders on PostgreSQL with a figure filtered
  by `active`, when the request runs, then it completes without a type error and
  returns the expected rows. *(BR-14)*
- **AC-15** — Given a mailbox whose `expired` holds each driver's own "never
  expires" sentinel, when an expiry-filtered figure is computed, then the
  mailbox is treated as not expired on both drivers, with no comparison against
  a literal sentinel. *(BR-14)*
- **AC-16** — Given a fixture whose mailbox count is increased tenfold, when the
  dashboard renders, then the number of model instances hydrated from `mailbox`
  does not grow with it. *(BR-09)*
- **AC-17** — Given any dashboard render, when the executed statements are
  inspected, then no statement joins a Mailward table to a `vmail` table.
  *(BR-10)*
- **AC-18** — Given a `last_login` lookup on both drivers, when it executes,
  then it is issued as an explicit `where('username', …)` and never as
  `find()`. *(BR-11)*
- **AC-19** — Given an unauthenticated request to `/dashboard`, when it is made,
  then it is redirected to `/login` and no figure is computed.
- **AC-20** — Given a domain with `aliases = 2`, two `alias` rows, six
  `forwardings` rows with `is_alias = 1` and three `alias_domain` rows targeting
  it, when the dashboard renders, then the domain appears in "domains at their
  limit" for the alias limit, the reported alias count is `2`, and no executed
  statement counts rows in `forwardings` or in `alias_domain`. *(BR-18)*

## Out of Scope

- Any write. The dashboard links to the features that write; it performs no
  action itself (BR-17).
- Drill-down screens, per-domain filters and date-range selection on this
  screen. Listing and filtering belong to `docs/features/domains.md`,
  `docs/features/mailboxes.md` and `docs/features/aliases.md`.
- The `domain.maillists` limit. Mailing lists are unmodelled in v1
  (`02-domain.md` §12), so the count that would be compared against it does not
  exist; `02-domain.md` §2 requires surfacing all three limits and this feature
  narrows that to the two that are computable.
- `domain.maxquota` as a limit figure — `02-domain.md` §2 describes it without
  assigning it a rule, and `docs/features/mailboxes.md` OQ-M6 already holds the
  question.
- Charts, historical series and trends. Nothing in `vmail` retains history, and
  Mailward stores no snapshots (`02-domain.md` §13).
- Service status, mail queue, log viewer, quarantine and throttling figures
  (`00-overview.md` §5, Later).
- Alias domain counts, mailing list counts and any `iredapd` or `amavisd`
  figure — those connections are not declared in v1 (`01-architecture.md` §3).
- Export of any figure.

## Open Questions

- **OQ-DASH-01** — What unit is `mailbox.quota`? `02-domain.md` §4 says bytes;
  the Dovecot `user_query` shipped by iRedMail and quoted in
  `docs/reference/open-questions-research.md` (OQ-03, established fact 1) builds
  its quota rule as `mailbox.quota*1048576`, which implies mebibytes. Every
  quota total on this screen is wrong by a factor of 1,048,576 if this is read
  the wrong way, and both readings render a plausible-looking number. Same
  question as `docs/features/mailboxes.md` OQ-M1; the dashboard is where a wrong
  answer becomes an administrator's decision.
- **OQ-DASH-02** — Do the account counts include inactive and expired rows, or
  only active, unexpired ones — and is the breakdown shown? The scope line says
  only "account counts". The same question decides whether a disabled domain
  contributes to the domain count.
- **OQ-DASH-04** — What is "last login" when `last_login` has three columns
  (`imap`, `pop3`, `lda`) — the greatest of the three, or one nominated
  protocol? And what threshold makes an account dormant? `02-domain.md` §10
  states the purpose without defining either.
- **OQ-DASH-05** — Are `domain_admins` rows with `active = 0` or a past
  `expired` excluded from the actor's domain set? Every figure on this screen
  changes with the answer.
  `docs/reference/open-questions-research.md` (OQ-02, Still unknown) records
  that nothing sourced says iRedMail reads either column. Same question as
  `docs/features/authentication.md` OQ-AUTH-05.
- **OQ-DASH-06** — How is a global admin's domain set resolved? If it is derived
  from `domain_admins`, the `ALL` sentinel row joins to no row in `domain` and
  the dashboard silently produces zero rows for exactly the actor who should see
  everything (`docs/reference/open-questions-research.md`, OQ-02, hypothesis 5).
  Blocked by OQ-02 in `00-overview.md` §9.
- **OQ-DASH-07** — Are the figures computed on every request, or cached with a
  stated staleness? Nothing in `docs/` decides it, and full-table aggregates per
  page load are the one place where this product's read pattern stops being
  trivially cheap.
