# Domains

Status: draft

## Purpose

Manage the rows of `vmail.domain`: list, create, edit, disable, re-enable and
delete mail domains, and hold the per-domain limits (`aliases`, `mailboxes`,
`maillists`) that every account-creating feature must check before it writes.

Scope line: `docs/00-overview.md` §5, v1 — "Domains: list, create, edit,
disable, delete; per-domain limits".

## Actors

| Actor | Interaction |
|---|---|
| Global admin | every domain, and the only actor who may write a `domain` row (BR-19) |
| Domain admin | sees only the domains assigned to them (`domain_admins`); no write to the domain record itself (BR-20) |
| Mail user | none — cannot log in (`docs/policies/authorization.md` §1) |

Authorization is `docs/policies/authorization.md` in full: the scope rule (§2),
enforcement in the query (§3), server-side re-authorization (§4) and audit (§7).
This feature adds no role. It narrows the policy in one direction only: every
write this feature performs is global-admin only (BR-19), so the scope rule
governs a domain admin's **visibility** here and never grants them a write
(BR-20).

## Business Rules

- **BR-01** — Every address and domain name accepted by this feature is trimmed
  and lower-cased in `prepareForValidation()`, before validation and before any
  lookup (`docs/decisions/0005-lowercase-canonical-addresses.md`). Stated once;
  it applies to every input field in this document.
- **BR-02** — Uniqueness of `domain.domain` is checked with an explicit query on
  the already-lower-cased value, inside the same transaction as the insert. The
  primary key is not relied upon to catch case duplicates: it catches them on
  MySQL (`utf8mb4_general_ci`) and misses them on PostgreSQL
  (`schema-type-matrix.md` D17; `0005` Consequences).
- **BR-03** — `aliases`, `mailboxes` and `maillists` are limits Mailward
  enforces **before** creating an account, and **`0` means unlimited, not "zero
  allowed"** (`02-domain.md` §2). The check runs in the same transaction as the
  account insert, on the current row count for that domain.
- **BR-04** — Every domain listing and detail response carries, per domain, the
  current count and the configured limit for alias accounts and mailboxes, and a
  derived `at_limit` flag — true when `limit > 0 and count >= limit`. The
  dashboard's "domains at their limit" figure (`00-overview.md` §5) is the same
  computation, not a second one (`02-domain.md` §2: limits "must surface on the
  dashboard when reached").
- **BR-05** — `maillists` is stored, displayed and editable, but is not enforced
  in v1: mailing lists are out of scope (`00-overview.md` §5 Later;
  `02-domain.md` §12), so no count exists to compare it against. No `at_limit`
  flag is produced for it.
- **BR-06** — `domain.quota` is historical and unused: never read, never
  written, never sent to the frontend, never present in an update statement
  (`02-domain.md` §2). The domain quota is `domain.maxquota`, in bytes.
- **BR-07** — `domain.settings` belongs to iRedAdmin-Pro: never read, never
  written, and preserved untouched by every update (`02-domain.md` §1.4).
- **BR-08** — `created` and `modified` are written explicitly on every insert
  and `modified` on every update. The column default is never allowed to fire:
  it is the `1970-01-01 01:01:01` sentinel on MySQL and `NOW()` on PostgreSQL,
  so nothing may infer "never set" from the stored value
  (`schema-type-matrix.md` D1; `02-domain.md` §1.2).
- **BR-09** — `expired` is written explicitly on insert with the *driver's own*
  never-expires sentinel — `9999-12-31 00:00:00` on MySQL,
  `9999-12-31 01:01:01` on PostgreSQL. Expiry is evaluated only as
  `expired > now()`; it is never compared for equality against a constant
  (`schema-type-matrix.md` D2). v1 provides no way to set an expiry date.
- **BR-10** — Neither driver has a native boolean: `active` and `backupmx` are
  `TINYINT(1)` on MySQL and `INT2` on PostgreSQL. Every read, write and where
  clause binds `1` or `0`, never `true` or `false` — PostgreSQL errors on
  `active = true` where MySQL silently accepts it (`schema-type-matrix.md` D4).
  Disabling a domain writes `active = 0`; re-enabling writes `active = 1`.
- **BR-11** — `description` and `disclaimer` are nullable `TEXT` on MySQL and
  `NOT NULL DEFAULT ''` on PostgreSQL. They are normalised to `''` on write, and
  `''` and `null` are treated as the same absent value on read
  (`schema-type-matrix.md` D6).
- **BR-12** — `aliases`, `mailboxes` and `maillists` are 32-bit `INT` on MySQL
  and 64-bit `INT8` on PostgreSQL. Validation caps them at the 32-bit signed
  maximum, `2147483647`, so no value is accepted that writes on one driver and
  fails or truncates on the other (`schema-type-matrix.md` D7). Minimum `0`.
  `maxquota` is `BIGINT`/`INT8` on both and is capped at the 64-bit signed
  maximum.
- **BR-13** — Deleting a domain removes its `domain_admins` rows
  (`docs/policies/authorization.md` BR-A03). An administrator left with no
  domains and no global flag can still log in and sees an empty state, not an
  error.
- **BR-14** — There are no foreign keys anywhere in this schema
  (`0002-separate-application-database.md`), so every cascade this feature
  performs is explicit application logic. All `vmail` rows a single operation
  touches are written in one transaction on the `vmail` connection. For any
  operation that also writes Mailward's own database, the `vmail` write is the
  last commit and the operation is idempotent on retry (`01-architecture.md` §3).
  Which rows a domain deletion must reach beyond BR-13 is BR-16.
- **BR-15** — Mailward issues DML only against `vmail`: `SELECT`, `INSERT`,
  `UPDATE`, `DELETE`. No DDL, no schema assumptions beyond
  `docs/reference/schema-type-matrix.md`
  (`0002-separate-application-database.md`).
- **BR-16** — Deleting a domain is an **explicit cascade**. It is neither
  refused because dependants exist, nor allowed to leave orphans: Mailward
  deletes the dependants itself, because `vmail` has no foreign keys to do it
  (`docs/reference/decisions-needed.md` D1;
  `0002-separate-application-database.md`). In one transaction on the `vmail`
  connection (BR-14), the deletion of domain `D` removes, in this order:
  1. every `mailbox` row whose `domain` is `D`, each one through the full
     mailbox cascade of `docs/features/mailboxes.md` BR-23 — including the
     `deleted_mailboxes` row that makes iRedMail's cron remove the files;
  2. every standalone `alias` row whose `domain` is `D`, each one through the
     alias cascade of `docs/features/aliases.md` BR-14 — the `alias` row and its
     `is_list` member rows;
  3. every `alias_domain` row whose `target_domain` is `D`
     (`docs/features/alias-domains.md` BR-10);
  4. every `forwardings` row that still has `domain = D`, whatever its
     discriminator flags — the residue the first two steps did not reach;
  5. its `domain_admins` rows (BR-13,
     `docs/policies/authorization.md` BR-A03);
  6. the `domain` row itself.
- **BR-17** — The same operation removes the rows in **Mailward's own** database
  keyed by an address of `D` — `panel_profiles`, `two_factor_secrets` and any
  other table keyed by an address (`02-domain.md` §13). `audit_log` is the
  exception: it is exempt from that cleanup and deliberately keeps its
  references to addresses that no longer exist (`02-domain.md` §13,
  `docs/features/audit-log.md` BR-08). No transaction can span
  the two databases (`01-architecture.md` §3), so the Mailward-side deletes
  commit **first** and the `vmail` transaction of BR-16 is the last commit, and
  the whole operation is **idempotent on retry**: repeating it after a partial
  failure deletes whatever remains, succeeds when nothing remains, and never
  writes a second `deleted_mailboxes` row for a mailbox whose `mailbox` row is
  already gone (`docs/reference/decisions-needed.md` D1).
- **BR-18** — `domain.aliases` bounds **standalone alias accounts only** — rows
  in `vmail.alias` whose `domain` is that domain (`02-domain.md` §6;
  `docs/reference/decisions-needed.md` D2). Per-account aliases
  (`forwardings.is_alias`, `02-domain.md` §5) and alias domains
  (`alias_domain`, §3) are **not** counted against it and are bounded by no
  per-domain limit in v1. The count BR-03 checks before an alias account is
  created, and the count BR-04 publishes, are both taken from `alias` alone; no
  count of `forwardings` or `alias_domain` rows enters either.
- **BR-19** — **Every write to a `domain` row is global-admin only.** Creating,
  editing, disabling, re-enabling and deleting a domain each require
  `mailbox.isglobaladmin = 1` (`docs/reference/decisions-needed.md` D8, decided
  2026-08-15). The scope rule (`docs/policies/authorization.md` §2) cannot
  authorize any of them: creating a domain adds a name to the mail server's
  namespace and the domain belongs to nobody at the moment it is created, and
  deleting one destroys every account inside it through the cascade of BR-16.
  Neither operation is scoped to a domain the actor already administers, so
  there is no domain under which a domain admin could be authorized. Refusal
  keeps the existing shape: a domain admin acting on a domain **in** their scope
  is refused with `403`, one acting on a domain outside it gets `404` so the
  scope does not leak existence (Contracts), and both are recorded as
  authorization failures (`docs/policies/authorization.md` §7). The two
  form-rendering routes, `GET /domains/create` and `GET /domains/{domain}/edit`,
  are covered by this rule: they exist only to submit a write.
- **BR-20** — A domain admin has **visibility** of the domains assigned to them
  and **no write authority over the domain record itself** (BR-19,
  `docs/reference/decisions-needed.md` D8, decided 2026-08-15). Their authority
  covers the *contents* of those domains — mailboxes, aliases, forwardings —
  which the feature documents owning those tables govern; this document decides
  nothing about it. Two consequences here: the authorization props this feature
  sends to the page never enable a create, edit, disable, enable or delete
  control for a domain admin (`docs/policies/authorization.md` §4, and the prop
  is UX only — the server refuses regardless); and **no `domain_admins` row is
  written as a side effect of creating a domain**, for the creator or for anyone
  else. The creator is always a global admin, who already sees every domain, so
  the question of whether a creator becomes an administrator of what they just
  created does not arise. Assigning a domain admin is
  `docs/features/domain-admins.md`, not this feature.
- **BR-21** — **Disabling a domain writes `domain.active = 0` and nothing
  else**, and re-enabling writes `domain.active = 1` and nothing else (BR-10;
  `docs/reference/decisions-needed.md` D9, decided 2026-08-15). Apart from
  `modified` (BR-08), the operation writes no other column and no other row:
  Mailward does not touch the `active` flag of any `mailbox`, `alias`,
  `forwardings` or `alias_domain` row of that domain. The reason is
  reversibility. If disabling also deactivated every account, re-enabling could
  not know which accounts were already inactive beforehand and would switch back
  on accounts that were meant to stay off; preserving that prior state would
  mean recording it in Mailward's own database, which is a mechanism nobody has
  asked for. The operation is therefore genuinely reversible — and it **depends
  on Postfix and Dovecot honouring the domain-level flag**, which is not
  confirmed and is held as OQ-DOM-03.
- **BR-22** — **A domain cannot be renamed, in v1 or by any workaround this
  feature offers.** `domain.domain` is written on create and never appears in an
  `UPDATE`; there is no rename endpoint, no rename field and no rename control
  (`docs/reference/decisions-needed.md` Q5, answered 2026-08-15). The reason is
  that a rename is not a rename: the name is denormalised into `mailbox.domain`,
  `alias.domain`, `forwardings.domain`, `forwardings.dest_domain`,
  `domain_admins.domain`, `used_quota.domain` and `last_login.domain`, and is
  embedded again inside `mailbox.username`, `alias.address` and `maildir` —
  which Dovecot resolves to a directory that already exists on disk. There is no
  foreign key to propagate any of it (BR-14). The **interface must say this
  plainly**: the only way to change a domain's name is to delete it and create
  the new one, and deleting it destroys every account inside it through the
  cascade of BR-16 — every mailbox, with a `deleted_mailboxes` row each, so
  iRedMail's cron removes the files. That sentence appears where an
  administrator would look for a rename, on the edit form. It is not presented
  as a workaround, because it is not one; it is a data-loss operation with the
  same wording as the delete confirmation.
- **BR-23** — A `domain` row's name **may** also exist as an `alias_domain` row,
  and this feature refuses no create on that ground: it performs no cross-table
  uniqueness check against `alias_domain`, `mailbox` or `alias`
  (`docs/reference/decisions-needed.md` Q4, answered 2026-08-15, option C). The
  only uniqueness this feature enforces is BR-02, within `domain` itself.
  Strictness is spent on the other half of that decision instead: **locality**,
  the rule that every address Mailward writes must have a domain part present in
  `domain`. This feature owns the table that rule is checked against — it is
  enforced by the features that write addresses (`docs/features/mailboxes.md`
  BR-26, `docs/features/aliases.md` BR-16,
  `docs/features/mailbox-aliases-forwardings.md` BR-16) — with one consequence
  here: **deleting a domain is the operation that would strand them**, and it
  does not, because BR-16 removes every address in the domain in the same
  transaction. What delivery does when a name is both a `domain` and an
  `alias_domain` is a property of the mail server, and the probe that observes
  it (`docs/reference/decisions-needed.md` E7) is informational rather than
  blocking.

## Data

Table `vmail.domain`, connection `vmail`, primary key `domain` (the domain
name). `$timestamps = false`; the columns are `created` / `modified`
(`02-domain.md` §1.5).

| Column | Used by this feature |
|---|---|
| `domain` | primary key, created, never updated (BR-22) |
| `description` | read/write, BR-11 |
| `disclaimer` | read/write, BR-11 |
| `aliases` | read/write, BR-03, BR-12 |
| `mailboxes` | read/write, BR-03, BR-12 |
| `maillists` | read/write, BR-05, BR-12 |
| `maxquota` | read/write, bytes |
| `quota` | **never touched**, BR-06 |
| `transport` | read/write, default `dovecot` (OQ-DOM-10) |
| `backupmx` | read/write, BR-10 |
| `settings` | **never touched**, BR-07 |
| `created`, `modified` | written explicitly, BR-08 |
| `expired` | written explicitly on insert, BR-09 |
| `active` | read/write, BR-10 |

Read for counts, never written by this feature: `mailbox` (rows where
`domain = :domain`), `alias` (rows where `domain = :domain`). The alias count is
`alias` only — never `forwardings`, never `alias_domain` (BR-18).

Written by this feature: `domain_admins` (deletion only, BR-13). On deletion
only, the cascade of BR-16 additionally writes `mailbox`, `alias`,
`alias_domain`, `forwardings` and `deleted_mailboxes`, each through the rules of
the feature that owns that table.

Mailward's own database: `audit_log`, one row per write and per authorization
failure (`docs/policies/authorization.md` §7) — never cleaned. On deletion only,
`panel_profiles`, `two_factor_secrets` and any other table keyed by an address
of the domain are cleaned (BR-17).

Notes:

- The two schema files declare `settings` and `backupmx` in opposite order
  (`schema-type-matrix.md` D18). Columns are always named explicitly; no
  positional result access.
- `domain_admins.domain` is `CHARACTER SET ascii` on MySQL while `domain.domain`
  is `utf8mb4` — any query joining the two raises an illegal-mix-of-collations
  error on MySQL unless one side is converted (`schema-type-matrix.md` D8). The
  deletion in BR-13 is therefore a direct `DELETE ... WHERE domain = ?`, not a
  join.

## Contracts

Inertia page routes and form endpoints. No JSON API is written for Mailward's
own frontend (`0004-inertia-vue.md`). Validation lives in FormRequests and is
never duplicated client-side; a validation failure is an Inertia redirect back
with the error bag, not a JSON body. Authorization props sent to the page are
for showing and hiding controls only (`docs/policies/authorization.md` §4).

| Route | Method | Page / effect | Authorization |
|---|---|---|---|
| `/domains` | GET | `Domains/Index` — paginated, domain-scoped list | global admin: every domain; domain admin: their assigned domains only (BR-20) |
| `/domains/create` | GET | `Domains/Create` | global admin only (BR-19) |
| `/domains` | POST | create | global admin only (BR-19) |
| `/domains/{domain}/edit` | GET | `Domains/Edit` | global admin only (BR-19) |
| `/domains/{domain}` | PUT | update | global admin only (BR-19) |
| `/domains/{domain}/disable` | POST | `active = 0`, nothing else (BR-21) | global admin only (BR-19) |
| `/domains/{domain}/enable` | POST | `active = 1`, nothing else (BR-21) | global admin only (BR-19) |
| `/domains/{domain}` | DELETE | delete | global admin only (BR-19) |

`GET /domains` is the only route in this table a domain admin may reach. On
every other route the refusal shape is the same (BR-19): `403` when the domain
is in the actor's scope, `404` when it is not — so a refusal never reveals that
an unassigned domain exists — and both are recorded as authorization failures
(`docs/policies/authorization.md` §7).

**`GET /domains`** — inputs: `search` (optional, matched against `domain` and
`description`), `page`. The domain scope is applied in the query, not after
fetching (`docs/policies/authorization.md` §3). Each row carries the BR-04
counts and flag.

**`POST /domains`** — fields and validation intent:

| Field | Intent |
|---|---|
| `domain` | required; valid domain name; ≤ 255 chars; not already present (BR-02) |
| `description` | optional string; `''` when absent (BR-11) |
| `disclaimer` | optional string; `''` when absent (BR-11) |
| `aliases` | required integer, `0`–`2147483647` (BR-12); `0` = unlimited |
| `mailboxes` | required integer, `0`–`2147483647` |
| `maillists` | required integer, `0`–`2147483647` |
| `maxquota` | required integer ≥ 0, bytes, ≤ 64-bit signed max |
| `transport` | optional string ≤ 255; defaults to `dovecot` (OQ-DOM-10) |
| `backupmx` | required boolean input, persisted as `1`/`0` (BR-10) |
| `active` | required boolean input, persisted as `1`/`0` (BR-10) |

Error cases: caller is not a global admin (BR-19) → 403, nothing written, logged
as an authorization failure — checked before validation, so a refused caller
learns nothing from the error bag; validation failure → redirect back with
errors, nothing written; duplicate domain (BR-02) → validation error on
`domain`.

**`PUT /domains/{domain}`** — same fields except `domain`, which is not
updatable and carries the statement required by BR-22 in its place. Error cases: caller is not a global admin (BR-19) →
403 for a domain in their scope, 404 for one outside it; unknown domain → 404,
so the scope does not leak existence; validation failure → redirect back with
errors; lowering `aliases` or `mailboxes` below the current
count is **not** rejected — the limit is checked on account creation (BR-03),
so the domain simply reports `at_limit` (BR-04).

**`POST /domains/{domain}/disable`**, **`/enable`** — no body. Each writes
`domain.active` and `modified` and nothing else; no account inside the domain is
touched, in either direction (BR-21). Error cases: caller is not a global admin
(BR-19) → 403 for a domain in their scope, 404 for one outside it; unknown
domain → 404.

**`DELETE /domains/{domain}`** — no body beyond the confirmation the UI
requires. Removes the `domain` row, its `domain_admins` rows (BR-13) and every
dependant enumerated in BR-16, in one `vmail` transaction (BR-14), after the
Mailward-side cleanup of BR-17. A domain that still owns accounts is **not**
refused: the cascade empties it. The confirmation the UI requires states how
many mailboxes, alias accounts and alias domains the cascade will remove. Error
cases: caller is not a global admin (BR-19) → 403 for a domain in their scope,
404 for one outside it, and nothing is written — no `deleted_mailboxes` row, no
Mailward-side cleanup; unknown domain → 404; a mailbox in the domain is the last
global admin → refused by
`docs/policies/authorization.md` BR-A01, nothing written.

## States

A domain is a row in `domain` in one of two managed states, plus a read-only
condition:

| State | Representation | Reached by |
|---|---|---|
| Active | `active = 1` | create with `active` on; `POST /enable` |
| Disabled | `active = 0` | create with `active` off; `POST /disable` |
| Deleted | row absent | `DELETE`; terminal, no transition out |

Active ⇄ Disabled is fully reversible and carries no other state: the accounts
inside the domain keep their own `active` values across both transitions
(BR-21). Every transition in the table is global-admin only (BR-19).

Expired is not a state Mailward drives: it is the condition `expired <= now()`
(BR-09), evaluated per request, on rows whose `expired` was set outside
Mailward. v1 offers no transition into or out of it (OQ-DOM-11).

Forbidden: any transition out of Deleted; writing `active` as `true`/`false`
(BR-10); changing `domain` (BR-22).

## Acceptance Criteria

Each runs against **both** MySQL and PostgreSQL (`01-architecture.md` §8).

- **AC-01**: given a global admin, when they post `domain = "Example.COM"`, then
  the stored primary key is `example.com`.
- **AC-02**: given a domain `example.com` exists, when a global admin posts
  `EXAMPLE.com`, then the request fails validation on `domain`, no row is
  inserted, and this holds on PostgreSQL as well as MySQL.
- **AC-03**: given a create request, when it succeeds, then `created` and
  `modified` are within the request window and are not the MySQL
  `1970-01-01 01:01:01` sentinel, and `expired` equals the running driver's
  never-expires sentinel — asserted per driver, never against one constant.
- **AC-04**: given a domain with `mailboxes = 0`, when the mailbox-creation
  action runs with 500 existing mailboxes in that domain, then no limit error is
  raised — `0` is unlimited.
- **AC-05**: given a domain with `mailboxes = 2` and two existing mailboxes,
  when the mailbox-creation action runs, then it fails with a limit error and no
  row is written to `mailbox` or `forwardings`.
- **AC-06**: given a create or update request with `aliases = 2147483648`, when
  it is submitted, then it fails validation and no statement reaches `vmail`.
- **AC-07**: given an update request with `description` and `disclaimer` empty,
  when it is applied on PostgreSQL, then it succeeds (no not-null violation) and
  both columns hold `''`; the same request on MySQL leaves the domain reading as
  having no description.
- **AC-08**: given an active domain, when `POST /domains/{domain}/disable` is
  called on PostgreSQL, then the request succeeds and `active` is the integer
  `0` — a boolean binding would raise a type error and fail this test.
- **AC-09**: given a domain whose `quota` and `settings` hold arbitrary
  pre-existing values, when any update succeeds, then both columns are byte-for-
  byte unchanged and neither appears in the Inertia props.
- **AC-10**: given a domain admin assigned to `example.com`, when they request
  `GET /domains`, then only `example.com` is returned and the exclusion is
  expressed in the SQL, not applied after fetching.
- **AC-11**: given a domain admin assigned to `example.com`, when they request
  `GET /domains/other.com/edit`, then the response is 404, no domain data is
  serialised, and an authorization failure is recorded in `audit_log`.
- **AC-12**: given `example.com` with two `domain_admins` rows, when a permitted
  actor deletes the domain, then the `domain` row and both `domain_admins` rows
  are gone and the deletion is recorded in `audit_log` with the before values.
- **AC-13**: given an administrator whose only domain was just deleted and who
  is not a global admin, when they log in, then login succeeds and
  `GET /domains` renders an empty list rather than an error
  (`docs/policies/authorization.md` BR-A03).
- **AC-14**: given a domain with `mailboxes = 5` and five mailboxes, when
  `GET /domains` is rendered, then that row reports `count = 5`, `limit = 5` and
  `at_limit = true`; a domain with `mailboxes = 0` and five mailboxes reports
  `at_limit = false`.
- **AC-15**: given a domain row whose `expired` is the never-expires sentinel of
  the driver under test, when the domain is listed, then it is not reported
  expired; given a row whose `expired` is yesterday, then it is reported
  expired — both asserted through `expired > now()`, with no sentinel constant
  in the test.
- **AC-16**: given `example.com` holding two mailboxes, one standalone alias
  with two `is_list` members, one `forwardings` row with `is_alias = 1`, one
  alias domain targeting it and two `domain_admins` rows, when a permitted actor
  deletes the domain, then the `domain` row, both `mailbox` rows, the `alias`
  row, its two member rows, the `alias_domain` row, every `forwardings` row
  whose `domain` is `example.com` and both `domain_admins` rows are all gone;
  exactly one `deleted_mailboxes` row exists per deleted mailbox; and every
  `vmail` statement of the deletion ran inside one transaction (BR-16).
- **AC-17**: given alias domains `other.net → example.com` and
  `keep.net → keep.com`, when `example.com` is deleted, then the `other.net` row
  is gone and both the `keep.net` row and the `keep.com` domain are untouched
  (BR-16).
- **AC-18**: given `admin@example.com` with rows in `panel_profiles` and
  `two_factor_secrets` and entries in `audit_log`, when `example.com` is
  deleted, then both Mailward-side rows are gone and every `audit_log` entry
  remains, still naming `admin@example.com` (BR-17).
- **AC-19**: given the `vmail` transaction of a domain deletion fails after the
  Mailward-side rows of BR-17 were committed, when the same deletion is retried,
  then it succeeds, every row named in BR-16 is gone, and exactly one
  `deleted_mailboxes` row exists for each mailbox that was deleted — never two
  (BR-17).
- **AC-20**: given `example.com` with `aliases = 2`, two `alias` rows, five
  `forwardings` rows with `is_alias = 1` and three `alias_domain` rows targeting
  it, when a third standalone alias account is created it fails with a limit
  error; when a sixth per-account alias and a fourth alias domain are created,
  both succeed and no limit error is raised (BR-18).
- **AC-21**: given that same domain, when `GET /domains` renders it, then the
  alias figures report `count = 2`, `limit = 2` and `at_limit = true`, and no
  statement executed by the listing counts rows in `forwardings` or in
  `alias_domain` (BR-18).
- **AC-22**: given a global admin, when they create a domain, update it, disable
  it, re-enable it and finally delete it, then every one of the five requests
  succeeds and each is recorded in `audit_log` — the permitted path of BR-19,
  exercised end to end.
- **AC-23**: given a domain admin assigned to `example.com`, when they
  `POST /domains` with a valid, otherwise-acceptable `new.com`, then the
  response is 403, no `domain` row exists for `new.com`, and an authorization
  failure naming their address is recorded in `audit_log` (BR-19).
- **AC-24**: given a domain admin assigned to `example.com`, when they
  `PUT /domains/example.com` with a changed `description` and `mailboxes`, then
  the response is 403 — not 404, because the domain is inside their scope — no
  column of the row changes, `modified` included, and an authorization failure
  is recorded (BR-19).
- **AC-25**: given an active `example.com` and a domain admin assigned to it,
  when they `POST /domains/example.com/disable`, then the response is 403 and
  `active` is still `1`; and given the same domain already disabled, when they
  `POST /domains/example.com/enable`, then the response is 403 and `active` is
  still `0` (BR-19).
- **AC-26**: given a domain admin assigned to `example.com`, which holds two
  mailboxes and one standalone alias, when they `DELETE /domains/example.com`,
  then the response is 403, the `domain` row and every dependant enumerated in
  BR-16 are still present, no `deleted_mailboxes` row was written, no
  Mailward-side row of BR-17 was removed, and an authorization failure is
  recorded (BR-19).
- **AC-27**: given a domain admin assigned to `example.com`, when they request
  `GET /domains/create` and `GET /domains/example.com/edit`, then both are
  refused — 403 for the edit page, since the domain is inside their scope — and
  no form and no domain data are serialised; and when they request
  `GET /domains`, which does render, then the authorization props on that page
  enable no create, edit, disable, enable or delete control (BR-19, BR-20).
- **AC-28**: given a global admin who is not an administrator of any specific
  domain, when they create `new.com`, then the create succeeds and the
  `domain_admins` table is byte-for-byte unchanged — no row is written for the
  creator or for anyone else (BR-20).
- **AC-29**: given `example.com` with three mailboxes of which one already has
  `active = 0`, two standalone alias accounts, a `forwardings` row per mailbox
  and an alias domain targeting it, when a global admin disables the domain,
  then `domain.active` is `0` and every `mailbox`, `alias`, `forwardings` and
  `alias_domain` row of that domain holds exactly the `active` value it held
  before — the already-inactive mailbox included. The only columns the operation
  writes are `domain.active` and `domain.modified` (BR-21, BR-08).
- **AC-30**: given that same domain immediately after being disabled, when a
  global admin re-enables it, then `domain.active` is `1` and every account's
  `active` is still its original value — in particular the mailbox that was
  inactive before the disable is still inactive, and was never switched on by
  the round trip (BR-21).
- **AC-31**: given an existing `example.com`, when a global admin submits
  `PUT /domains/example.com` with a different `domain` value in the payload,
  then the primary key is unchanged, no `UPDATE` statement issued by the request
  contains the `domain` column, and the application's routes resolve nothing for
  renaming a domain (BR-22).
- **AC-32**: given a global admin on `GET /domains/example.com/edit`, when the
  page renders, then it states that the domain name cannot be changed and that
  the only equivalent — delete and re-create — destroys every account in the
  domain; and the page offers no rename control, no rename field and no link
  that performs one (BR-22).
- **AC-33**: given `example.net` already exists as an `alias_domain` row
  targeting `example.com`, when a global admin creates a `domain` row named
  `example.net`, then the create succeeds, both rows exist, and no statement
  executed by the create queried `alias_domain`, `mailbox` or `alias` for a
  conflicting name (BR-23).

## Out of Scope

- Creating, editing or deleting mailboxes, aliases, forwardings and domain
  admins outside the deletion cascade. This feature owns the limits (BR-03), the
  `domain_admins` deletion (BR-13) and the cascade of BR-16; the account
  features own their own writes and define the per-object cascades BR-16
  invokes.
- Alias domains — `docs/features/alias-domains.md`.
- The dashboard itself. It consumes BR-04, it does not define it.
- Per-domain iRedAPD, Amavis or mailing-list settings (`00-overview.md` §5
  Later).
- `domain.settings` and `domain.quota` (BR-06, BR-07).
- Renaming a domain (BR-22) — including any "rename" implemented as
  delete-and-recreate on the administrator's behalf. The interface states what
  the manual equivalent costs; it does not perform it.
- Setting or clearing a domain expiry date (BR-09).
- Deactivating the accounts inside a domain when the domain is disabled, and
  recording their prior state anywhere so it could be restored (BR-21).
- Assigning or removing domain administrators —
  `docs/features/domain-admins.md`. This feature only deletes `domain_admins`
  rows as part of the cascade (BR-13) and never writes one on create (BR-20).

## Open Questions

- **OQ-DOM-03** — Confirm, against a running install, that the mail server
  honours `domain.active = 0`: that Postfix refuses mail addressed to an account
  in a disabled domain, and that an account in a disabled domain cannot
  authenticate to Dovecot — both observed while the accounts' own `active` flags
  are still `1`, which is the state BR-21 leaves them in. The probe is
  `docs/reference/decisions-needed.md` E6. If the flag turns out **not** to be
  honoured, the decision in BR-21 has to be revisited, because a "disabled"
  domain that still receives mail and still lets its users log in is worse than
  having no disable action at all.
- **OQ-DOM-10** — What values are valid for `domain.transport`? Both schema
  files declare it free-text `VARCHAR` with no constraint
  (`schema-type-matrix.md`, Unverified 10), so there is nothing to validate
  against beyond length. The shipped default is `dovecot`.
- **OQ-DOM-11** — How is a domain whose `expired` is already in the past —
  written outside Mailward — presented and treated?
  `docs/policies/authorization.md` §6 defines expiry only for the login gate.
