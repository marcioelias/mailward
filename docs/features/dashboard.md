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
- **BR-19** — **Every count on this screen counts every row, and carries an "of
  which inactive" figure beside it.** The headline number is the unfiltered count
  — mailboxes, standalone aliases and domains alike — so it matches the total the
  corresponding listing reports, and the obvious follow-up is answered on the
  same screen instead of on a second one. The breakdown figure counts the rows in
  scope that are **not live**: `active = 0`, or `expired <= now()` where the
  table has an `expired` column (`mailbox`, `alias`, `domain` all do). A row that
  is both inactive and expired is counted once in the breakdown, not twice. The
  same answer governs the **domain count**: a disabled domain is counted in the
  headline and reported in its breakdown, and it is not removed from the
  aggregate — its accounts still exist and are still administered. Both figures
  bind `1`/`0` and evaluate `expired > now()` per BR-14, never against a sentinel
  constant. The alternative — counting only live rows — was refused because the
  dashboard figure would then disagree with every listing total in the product,
  which is a support ticket waiting to happen rather than a nuance
  (`docs/reference/decisions-needed.md` Q21, answered 2026-08-15, option A).
- **BR-20** — **"Last login" is the greater of `last_login.imap` and
  `last_login.pop3`. `lda` is excluded.** `lda` records a *delivery* into the
  account, not a person connecting to it: an account nobody has read for two
  years looks active under `lda` for as long as anything still sends mail to it,
  which inverts the figure this screen exists to give. The two remaining columns
  are compared after the reliability filter of BR-12, so an unreliable value
  never wins the comparison: if one column is unreliable the other is used alone,
  and if both are unreliable the account's last login is reported **unknown** and
  the account is not ranked (BR-12). If both are absent or NULL the account is
  reported as **never logged in**, which stays distinguishable from unknown
  (BR-13). **An account is dormant when it has no such login within a
  configurable threshold whose default is 90 days.** The threshold is an instance
  setting; a change to it is a `settings` write and is therefore audited
  (`docs/features/audit-log.md` BR-17). The default has no external basis and is
  a chosen number, and the screen states the threshold in force beside the figure
  so the reader is never guessing which one produced it. Accounts reported
  unknown and accounts reported never are **not** silently folded into the
  dormant count: never-logged-in accounts are counted and labelled separately,
  and unknown accounts are excluded from both counts and surfaced as a
  `degraded` figure (States) rather than as zero
  (`docs/reference/decisions-needed.md` Q22, answered 2026-08-15, option A).

## Data

**Read — `vmail`, all read-only in this feature:**

| Table | Columns | Use |
|---|---|---|
| `domain` | `domain`, `aliases`, `mailboxes`, `maillists`, `maxquota`, `active`, `expired` | limits (BR-07, BR-08), domain count |
| `mailbox` | `username`, `domain`, `quota`, `active`, `expired` | account count, allocated quota, correlation key for usage (BR-03) |
| `alias` | `address`, `domain`, `active` | standalone alias count, and the only population counted against `domain.aliases` (BR-18) |
| `used_quota` | `username`, `bytes`, `messages` — **never `domain`** | usage (BR-03, BR-04) |
| `last_login` | `username`, `imap`, `pop3` — **never `lda`** | dormancy (BR-11, BR-12, BR-13, BR-20) |
| `domain_admins` | `username`, `domain` | the actor's scope (`policies/authorization.md` §2) |

`domain.aliases`, `mailboxes` and `maillists` are 32-bit on MySQL and 64-bit on
PostgreSQL (matrix D7); they are read here and never written.

**Figures produced** (each within the actor's scope, BR-01):

| Figure | Definition |
|---|---|
| Domains | count of every `domain` row in scope, with "of which inactive" beside it (BR-19) |
| Mailboxes | count of every `mailbox` row in scope, with "of which inactive" beside it (BR-19) |
| Standalone aliases | count of every `alias` row in scope, with "of which inactive" beside it (BR-19) |
| Quota allocated | sum of `mailbox.quota` in scope, overall and per domain |
| Quota used | sum of `used_quota.bytes` for the mailboxes in scope, overall and per domain, correlated by `username` (BR-03) |
| Domains at their limit | domains in scope where a non-zero limit has been reached (BR-07, BR-08); the alias limit counts `alias` rows only (BR-18) |
| Dormant accounts | mailboxes in scope whose last login — the greater of `imap` and `pop3`, `lda` excluded — is older than the configured threshold, default 90 days; unreliable values excluded, never-logged-in counted separately (BR-20, BR-12, BR-13) |
| Never logged in | mailboxes in scope with no `last_login` row and no non-NULL `imap` or `pop3` value; counted and labelled separately from dormant (BR-20, BR-13) |

Every count above is an all-rows count with an "of which inactive" figure beside
it, and that includes the domain count (BR-19). The unit of `mailbox.quota` is
OQ-DASH-01 and every quota figure above depends on it.

**Mailward's own database**: nothing is written by this feature, and the only
thing read is the dormancy threshold of BR-20 from the instance `settings`. It is
read as a scalar before the aggregates run, so no figure crosses the two
databases in a single query (BR-10) and nothing joins them (BR-17 keeps the
screen read-only; changing the threshold is the settings feature's write, not
this one's).

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
  `last_login` value (BR-12) suppresses the dormancy figure, not the counts. An
  account whose `imap` and `pop3` values are both unreliable is reported unknown
  and enters neither the dormant count nor the never-logged-in count (BR-20).
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
- **AC-21** — Given a domain in scope holding five mailboxes of which two have
  `active = 0` and one has an `expired` date in the past, when the dashboard
  renders, then the mailbox count is `5` and the "of which inactive" figure is
  `3`; and the same count equals the paginator total of `GET /mailboxes` for the
  same actor. *(BR-19)*
- **AC-22** — Given a mailbox that is both `active = 0` and expired, when the
  breakdown is computed, then it contributes `1` to the inactive figure, not `2`.
  *(BR-19)*
- **AC-23** — Given a global admin, three domains of which one is disabled and
  one is expired, when the dashboard renders, then the domain count is `3` with
  an inactive figure of `2`, and the disabled domain's mailboxes are still
  included in the mailbox count. *(BR-19)*
- **AC-24** — Given the counts run on PostgreSQL, when the aggregate statements
  are inspected, then each is a single aggregate query per figure, `active` is
  bound as `1`/`0` and expiry is expressed as `expired > now()` with no sentinel
  literal in any statement. *(BR-19, BR-14, BR-09)*
- **AC-25** — Given a mailbox whose `lda` is today and whose `imap` and `pop3`
  are both two years old, when the dormancy figure is computed with the default
  threshold, then the account **is** dormant; and when the executed statements
  and the computed props are inspected, then no `lda` value entered the
  comparison. *(BR-20)*
- **AC-26** — Given a mailbox whose `imap` is 200 days old and whose `pop3` is 10
  days old, when the figure is computed at the default 90-day threshold, then the
  account is not dormant — the greater of the two decides. *(BR-20)*
- **AC-27** — Given a mailbox whose `imap` is negative or in the future and whose
  `pop3` is 200 days old, when the figure is computed, then the `pop3` value
  decides and the account is dormant; and given both values are unreliable, then
  the account's last login is reported unknown, it appears in neither the dormant
  count nor the never-logged-in count, and the figure is rendered `degraded`
  rather than `0`. *(BR-20, BR-12)*
- **AC-28** — Given a mailbox with no `last_login` row, and a mailbox whose row
  has NULL in both `imap` and `pop3`, when the figures are computed, then both
  are counted as never logged in, both are distinguishable in the props from the
  unknown case of AC-27, and neither is counted as dormant. *(BR-20, BR-13)*
- **AC-29** — Given the threshold is changed from 90 to 30 days, when the
  dashboard is rendered again, then an account last seen 60 days ago moves into
  the dormant count, the screen states `30 days` beside the figure, and an
  `audit_log` entry exists for the `settings` write. *(BR-20,
  `docs/features/audit-log.md` BR-17)*
- **AC-30** — Given a fresh install whose threshold has never been configured,
  when the dashboard renders, then the threshold in force is 90 days and it is
  stated on the screen. *(BR-20)*

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
- `domain.maxquota` as a limit figure. It is now a rule —
  `docs/features/mailboxes.md` BR-28 makes it a cap on an **individual** mailbox
  quota rather than on the domain's total — so there is no domain-level
  allocation figure for it to be compared against, and it stays off this screen.
  Whether a mailbox exceeds its domain's cap is refused at write time, not
  reported here.
- Setting the dormancy threshold. The threshold is read here (BR-20) and written
  by the settings feature; this screen offers no control that changes it.
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
- **OQ-DASH-06** — How is a global admin's domain set resolved? If it is derived
  from `domain_admins`, the `ALL` sentinel row joins to no row in `domain` and
  the dashboard silently produces zero rows for exactly the actor who should see
  everything (`docs/reference/open-questions-research.md`, OQ-02, hypothesis 5).
  Blocked by OQ-02 in `00-overview.md` §9.
- **OQ-DASH-07** — Are the figures computed on every request, or cached with a
  stated staleness? Nothing in `docs/` decides it, and full-table aggregates per
  page load are the one place where this product's read pattern stops being
  trivially cheap.
