# Alias Domains

Status: draft

## Purpose

Manage the rows of `vmail.alias_domain`: list, create, retarget, disable,
re-enable and delete the mappings `alias_domain → target_domain`. Mail addressed
to the alias domain is delivered to the accounts of the target domain
(`02-domain.md` §3), so the mapping is the whole feature — an alias domain has
no accounts of its own.

Scope line: `docs/00-overview.md` §5, v1 — "Alias domains".

## Actors

| Actor | Interaction |
|---|---|
| Global admin | every alias domain |
| Domain admin | only alias domains whose `target_domain` is assigned to them (BR-05) |
| Mail user | none — cannot log in (`docs/policies/authorization.md` §1) |

Authorization is `docs/policies/authorization.md` in full — §2 scope rule, §3
enforcement in the query, §4 server-side re-authorization, §7 audit. This
feature narrows nothing. Whether a domain admin may create or delete an alias
domain at all is unresolved (OQ-AD-04).

## Business Rules

- **BR-01** — Every domain name accepted by this feature is trimmed and
  lower-cased in `prepareForValidation()`, before validation and before any
  lookup (`docs/decisions/0005-lowercase-canonical-addresses.md`). Stated once;
  it applies to both `alias_domain` and `target_domain`.
- **BR-02** — `target_domain` must exist as a row in `domain`. Nothing in the
  schema enforces it (`02-domain.md` §3), so it is checked with an explicit
  query on the `vmail` connection, inside the same transaction as the write.
  A consequence, not a second rule: `target_domain` can therefore never itself
  be an alias domain, because an alias domain has no `domain` row.
- **BR-03** — `alias_domain` is the primary key. One row per alias domain;
  many alias domains may point at the same `target_domain`
  (`schema-type-matrix.md` §2).
- **BR-04** — Uniqueness of `alias_domain` is checked with an explicit query on
  the already-lower-cased value, inside the same transaction as the insert. The
  primary key is not relied upon to catch case duplicates: it catches them on
  MySQL and misses them on PostgreSQL (`schema-type-matrix.md` D17; `0005`
  Consequences).
- **BR-05** — For the scope rule, an alias domain resolves its domain as
  `target_domain`. `docs/policies/authorization.md` §2 names alias domains as
  domain-owned resources, and `target_domain` is the only column that resolves
  to a row in `domain` (BR-02). The filter is applied in the query
  (`docs/policies/authorization.md` §3).
- **BR-06** — `alias_domain` has **no `expired` column** — neither schema file
  gives it one (`schema-type-matrix.md` §2). No expiry is written on insert, no
  expiry filter is applied on read, and any shared expiry scope or `expired`
  cast used by the other mail models must not be applied to this model. An alias
  domain has exactly two columns of state: `active`, and the row's existence.
- **BR-07** — Neither driver has a native boolean: `active` is `TINYINT(1)` on
  MySQL and `INT2` on PostgreSQL. Every read, write and where clause binds `1`
  or `0`, never `true` or `false` — PostgreSQL errors on `active = true` where
  MySQL silently accepts it (`schema-type-matrix.md` D4). Disabling writes
  `active = 0`; re-enabling writes `active = 1`.
- **BR-08** — `created` and `modified` are written explicitly on insert, and
  `modified` on every update. The column default is never allowed to fire: it is
  the `1970-01-01 01:01:01` sentinel on MySQL and `NOW()` on PostgreSQL, so
  nothing may infer "never set" from the stored value (`schema-type-matrix.md`
  D1; `02-domain.md` §1.2).
- **BR-09** — There are no foreign keys (`0002-separate-application-database.md`),
  so BR-02 is a check, not a constraint. What the deletion of a target domain
  does to these rows is BR-10. Mailward issues DML only against `vmail`, and for
  any operation that also writes Mailward's own database the `vmail` write is
  the last commit and the operation is idempotent on retry
  (`01-architecture.md` §3).
- **BR-10** — Deleting a `domain` deletes every `alias_domain` row whose
  `target_domain` names it, in the same `vmail` transaction as the domain
  deletion — an explicit cascade, because there is no foreign key to perform one
  (`docs/reference/decisions-needed.md` D1; `docs/features/domains.md` BR-16).
  This feature never issues that delete itself; the domains feature does. What
  this feature gains is that BR-02 holds permanently rather than only at write
  time: **no `alias_domain` row outlives the `domain` row it names**, so no read
  path here has to tolerate a dangling `target_domain`.
- **BR-11** — An `alias_domain` row counts against **no** per-domain limit.
  `domain.aliases` bounds standalone alias accounts (`vmail.alias`) only
  (`docs/reference/decisions-needed.md` D2; `docs/features/domains.md` BR-18),
  and `domain.mailboxes` and `domain.maillists` bound populations this table is
  not part of. This feature therefore performs no limit check on create, and
  that absence is a decision, not an omission.

## Data

Table `vmail.alias_domain`, connection `vmail`, primary key `alias_domain`.
Five columns only. `$timestamps = false`; the columns are `created` /
`modified` (`02-domain.md` §1.5).

| Column | Used by this feature |
|---|---|
| `alias_domain` | primary key, created, not updated in place (OQ-AD-02) |
| `target_domain` | read/write, BR-02, BR-05 |
| `created`, `modified` | written explicitly, BR-08 |
| `active` | read/write, BR-07 |

Read, never written by this feature: `domain` (existence check, BR-02;
`domain_admins` scope resolution via BR-05).

Mailward's own database: `audit_log`, one row per write and per authorization
failure (`docs/policies/authorization.md` §7).

Notes:

- The table has no `TEXT` columns, so D6 (nullable on MySQL, `NOT NULL ''` on
  PostgreSQL) does not arise here.
- The table has no `expired` column and no `settings` column — BR-06.
- `domain_admins.domain` is `CHARACTER SET ascii` on MySQL while
  `alias_domain.target_domain` is `utf8mb4`; a join between them raises an
  illegal-mix-of-collations error on MySQL unless one side is converted
  (`schema-type-matrix.md` D8). The BR-05 scope filter therefore resolves the
  actor's administered domains first and binds them as a list, rather than
  joining the two tables.

## Contracts

Inertia page routes and form endpoints. No JSON API is written for Mailward's
own frontend (`0004-inertia-vue.md`). Validation lives in FormRequests; a
validation failure is an Inertia redirect back with the error bag. Authorization
props are for showing and hiding controls only
(`docs/policies/authorization.md` §4).

| Route | Method | Page / effect |
|---|---|---|
| `/alias-domains` | GET | `AliasDomains/Index` — paginated, scoped by BR-05 |
| `/alias-domains/create` | GET | `AliasDomains/Create` |
| `/alias-domains` | POST | create |
| `/alias-domains/{aliasDomain}/edit` | GET | `AliasDomains/Edit` |
| `/alias-domains/{aliasDomain}` | PUT | retarget and/or set `active` |
| `/alias-domains/{aliasDomain}/disable` | POST | `active = 0` |
| `/alias-domains/{aliasDomain}/enable` | POST | `active = 1` |
| `/alias-domains/{aliasDomain}` | DELETE | delete |

**`GET /alias-domains`** — inputs: `target_domain` (optional filter), `search`
(optional, matched against `alias_domain`), `page`. Scope from BR-05 is applied
in the query.

**`POST /alias-domains`** — fields and validation intent:

| Field | Intent |
|---|---|
| `alias_domain` | required; valid domain name; ≤ 255 chars; not already an `alias_domain` row (BR-04) |
| `target_domain` | required; must exist in `domain` (BR-02); must be in the actor's scope (BR-05) |
| `active` | required boolean input, persisted as `1`/`0` (BR-07) |

Error cases: validation failure → redirect back with errors, nothing written;
`target_domain` absent from `domain` → validation error on `target_domain`;
`alias_domain` already present → validation error on `alias_domain`;
`target_domain` outside the actor's scope → 403, recorded as an authorization
failure; caller not permitted at all (OQ-AD-04) → 403.

**`PUT /alias-domains/{aliasDomain}`** — fields `target_domain` and `active`.
`alias_domain` itself is not updatable (OQ-AD-02). Error cases: unknown or
out-of-scope alias domain → 404, so the scope does not leak existence; new
`target_domain` absent from `domain` → validation error; new `target_domain`
outside the actor's scope → 403.

**`POST /alias-domains/{aliasDomain}/disable`**, **`/enable`** — no body. Error
cases: unknown or out-of-scope → 404; not permitted → 403.

**`DELETE /alias-domains/{aliasDomain}`** — removes the single `alias_domain`
row. Error cases: unknown or out-of-scope → 404; not permitted (OQ-AD-04) → 403.

## States

| State | Representation | Reached by |
|---|---|---|
| Active | `active = 1` | create with `active` on; `POST /enable` |
| Disabled | `active = 0` | create with `active` off; `POST /disable` |
| Deleted | row absent | `DELETE`; terminal, no transition out |

There is no expired state: the table has no `expired` column (BR-06).

Retargeting is not a state transition — it changes `target_domain` while the row
stays in whichever of the two states it already occupies.

Forbidden: any transition out of Deleted; writing `active` as `true`/`false`
(BR-07); changing `alias_domain` (OQ-AD-02); any state in which
`target_domain` names a row absent from `domain` — checked at write time by
BR-02, and prevented afterwards by the cascade of BR-10.

## Acceptance Criteria

Each runs against **both** MySQL and PostgreSQL (`01-architecture.md` §8).

- **AC-01**: given a global admin and an existing domain `example.com`, when
  they post `alias_domain = "Example.NET"`, `target_domain = "EXAMPLE.com"`,
  then the stored row is `example.net → example.com`.
- **AC-02**: given no `domain` row for `nope.com`, when a global admin posts an
  alias domain targeting it, then the request fails validation on
  `target_domain` and no row is inserted into `alias_domain`.
- **AC-03**: given `example.net` already exists as an alias domain, when a
  global admin posts `EXAMPLE.NET`, then the request fails validation and no
  second row is inserted — asserted on PostgreSQL as well as MySQL.
- **AC-04**: given domains `a.com` and `b.com`, when two alias domains both
  target `a.com`, then both rows exist and both are returned by
  `GET /alias-domains?target_domain=a.com`.
- **AC-05**: given a create request, when it succeeds, then the insert statement
  contains no `expired` column, the row has `created` and `modified` within the
  request window, and neither equals the MySQL `1970-01-01 01:01:01` sentinel.
- **AC-06**: given a domain admin assigned only to `a.com`, and alias domains
  targeting `a.com` and `b.com`, when they request `GET /alias-domains`, then
  only the `a.com` one is returned and the exclusion is expressed in the SQL,
  not applied after fetching.
- **AC-07**: given a domain admin assigned only to `a.com`, when they post an
  alias domain targeting `b.com`, then the response is 403, no row is written,
  and an authorization failure is recorded in `audit_log`.
- **AC-08**: given a domain admin assigned only to `a.com`, when they request
  the edit page of an alias domain targeting `b.com`, then the response is 404
  and no alias-domain data is serialised.
- **AC-09**: given an active alias domain, when `POST .../disable` is called on
  PostgreSQL, then the request succeeds and `active` is the integer `0` — a
  boolean binding would raise a type error and fail this test.
- **AC-10**: given an alias domain `example.net → a.com` and an existing domain
  `b.com` in scope, when it is retargeted to `b.com`, then `target_domain` is
  `b.com`, `modified` is updated, `created` is unchanged, and `alias_domain` is
  unchanged.
- **AC-11**: given an alias domain, when a permitted actor deletes it, then
  exactly one row is removed from `alias_domain`, no row in `domain` is
  affected, and the deletion is recorded in `audit_log` with the before values.
- **AC-12**: given any alias-domain read path, when the model is queried, then
  no `expired` predicate appears in the SQL — a global expiry scope leaking onto
  this model would produce a missing-column error and fail this test.
- **AC-13**: given alias domains `a.net` and `b.net` both targeting
  `example.com`, and `c.net` targeting `other.com`, when `example.com` is
  deleted, then the `a.net` and `b.net` rows are gone, `c.net` and `other.com`
  are unchanged, and `GET /alias-domains` never returns a row whose
  `target_domain` is absent from `domain` (BR-10).
- **AC-14**: given `example.com` with `aliases = 1` and one existing `alias`
  row, when an alias domain targeting `example.com` is created, then it succeeds
  and no statement executed by the create counts rows in `alias`, `forwardings`
  or `alias_domain` (BR-11).

## Out of Scope

- Creating or managing accounts. An alias domain has no mailboxes, aliases or
  forwardings of its own; delivery resolves to the target domain's accounts
  (`02-domain.md` §3).
- Per-domain limits. `domain.aliases`, `domain.mailboxes` and `domain.maillists`
  are specified in `docs/features/domains.md` (BR-03 and BR-18 there); an alias
  domain counts toward none of them (BR-11).
- Deleting the target domain, and its effect on these rows — the write belongs
  to `docs/features/domains.md` BR-16, and is restated here as BR-10 only
  because this feature depends on the invariant it produces.
- Renaming an alias domain in place (OQ-AD-02).
- Catch-all addresses, per-alias-domain transports, backup MX behaviour — none
  of these exist on this table.

## Open Questions

- **OQ-AD-01** — May an `alias_domain` value also exist as a row in `domain`,
  and may the domains feature create a `domain` whose name is already an
  `alias_domain`? Nothing in `docs/` forbids either, nothing in the schema
  prevents either, and the resulting row pair describes a domain that is
  simultaneously real and aliased.
- **OQ-AD-02** — Is `alias_domain` editable in place, or is a name change a
  delete followed by a create? It is the primary key, and whether any other
  table may hold an alias-domain name — `forwardings.dest_domain` is the
  candidate (`02-domain.md` §5) — is not stated in `docs/` and is unverified
  against a real install.
- **OQ-AD-04** — May a domain admin create or delete an alias domain pointing at
  a domain they administer, or are those operations global-admin only? The
  equivalent question for domains is decided — every write to a `domain` row is
  global-admin only (`docs/features/domains.md` BR-19;
  `docs/reference/decisions-needed.md` D8, decided 2026-08-15) — but the answer
  here need not be the same, and this document does not assume it: an alias
  domain adds a name to the mail server's namespace, which the domains feature
  restricts separately.
- **OQ-AD-06** — May an alias domain point at a target domain that is disabled
  (`active = 0`) or expired, and does disabling a target domain change anything
  about its alias domains? Depends on OQ-DOM-03.
