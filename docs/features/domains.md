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
| Global admin | every domain |
| Domain admin | only the domains assigned to them (`domain_admins`) |
| Mail user | none — cannot log in (`docs/policies/authorization.md` §1) |

Authorization is `docs/policies/authorization.md` in full: the scope rule (§2),
enforcement in the query (§3), server-side re-authorization (§4) and audit (§7).
This feature narrows nothing and adds no role. Which of the operations below a
domain admin may perform at all is unresolved — see OQ-DOM-01 and OQ-DOM-02.

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
  exception: it is append-only and deliberately keeps its references to
  addresses that no longer exist (`02-domain.md` §13). No transaction can span
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

## Data

Table `vmail.domain`, connection `vmail`, primary key `domain` (the domain
name). `$timestamps = false`; the columns are `created` / `modified`
(`02-domain.md` §1.5).

| Column | Used by this feature |
|---|---|
| `domain` | primary key, created, never updated in v1 (OQ-DOM-08) |
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

| Route | Method | Page / effect |
|---|---|---|
| `/domains` | GET | `Domains/Index` — paginated, domain-scoped list |
| `/domains/create` | GET | `Domains/Create` |
| `/domains` | POST | create |
| `/domains/{domain}/edit` | GET | `Domains/Edit` |
| `/domains/{domain}` | PUT | update |
| `/domains/{domain}/disable` | POST | `active = 0` |
| `/domains/{domain}/enable` | POST | `active = 1` |
| `/domains/{domain}` | DELETE | delete |

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

Error cases: validation failure → redirect back with errors, nothing written;
duplicate domain (BR-02) → validation error on `domain`; caller not permitted
(OQ-DOM-01) → 403, logged as an authorization failure.

**`PUT /domains/{domain}`** — same fields except `domain`, which is not
updatable in v1 (OQ-DOM-08). Error cases: unknown or out-of-scope domain → 404
for a domain admin, so the scope does not leak existence; validation failure →
redirect back with errors; lowering `aliases` or `mailboxes` below the current
count is **not** rejected — the limit is checked on account creation (BR-03),
so the domain simply reports `at_limit` (BR-04).

**`POST /domains/{domain}/disable`**, **`/enable`** — no body. Error cases:
unknown or out-of-scope domain → 404; not permitted (OQ-DOM-02) → 403.

**`DELETE /domains/{domain}`** — no body beyond the confirmation the UI
requires. Removes the `domain` row, its `domain_admins` rows (BR-13) and every
dependant enumerated in BR-16, in one `vmail` transaction (BR-14), after the
Mailward-side cleanup of BR-17. A domain that still owns accounts is **not**
refused: the cascade empties it. The confirmation the UI requires states how
many mailboxes, alias accounts and alias domains the cascade will remove. Error
cases: unknown or out-of-scope domain → 404; not permitted (OQ-DOM-02) → 403; a
mailbox in the domain is the last global admin → refused by
`docs/policies/authorization.md` BR-A01, nothing written.

## States

A domain is a row in `domain` in one of two managed states, plus a read-only
condition:

| State | Representation | Reached by |
|---|---|---|
| Active | `active = 1` | create with `active` on; `POST /enable` |
| Disabled | `active = 0` | create with `active` off; `POST /disable` |
| Deleted | row absent | `DELETE`; terminal, no transition out |

Expired is not a state Mailward drives: it is the condition `expired <= now()`
(BR-09), evaluated per request, on rows whose `expired` was set outside
Mailward. v1 offers no transition into or out of it (OQ-DOM-11).

Forbidden: any transition out of Deleted; writing `active` as `true`/`false`
(BR-10); changing `domain` (OQ-DOM-08).

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
- Renaming a domain (OQ-DOM-08).
- Setting or clearing a domain expiry date (BR-09).

## Open Questions

- **OQ-DOM-01** — May a domain admin create a domain? The scope rule
  (`docs/policies/authorization.md` §2) cannot answer it: a domain being created
  belongs to no one yet. If yes, is the creator automatically written into
  `domain_admins` for the new domain?
- **OQ-DOM-02** — May a domain admin disable or delete a domain they
  administer, or are those two operations global-admin only?
- **OQ-DOM-03** — What does disabling a domain (`active = 0`) do to its
  mailboxes and aliases? Whether Postfix and Dovecot already refuse the whole
  domain on that flag, or whether Mailward must also deactivate each account, is
  not stated anywhere in `docs/` — and it decides what the disable action
  writes and whether it is reversible.
- **OQ-DOM-08** — Can a domain be renamed? `domain.domain` is the primary key
  and is denormalised into `mailbox.domain`, `alias.domain`,
  `forwardings.domain` and `forwardings.dest_domain`, `domain_admins.domain`,
  `used_quota.domain` and `last_login.domain`, with no foreign key to propagate
  a change. Nothing in `docs/` says whether renaming is supported.
- **OQ-DOM-10** — What values are valid for `domain.transport`? Both schema
  files declare it free-text `VARCHAR` with no constraint
  (`schema-type-matrix.md`, Unverified 10), so there is nothing to validate
  against beyond length. The shipped default is `dovecot`.
- **OQ-DOM-11** — How is a domain whose `expired` is already in the past —
  written outside Mailward — presented and treated?
  `docs/policies/authorization.md` §6 defines expiry only for the login gate.
