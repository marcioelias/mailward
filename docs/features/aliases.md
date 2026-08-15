# Standalone Aliases

Status: draft

Scope line: `docs/00-overview.md` §5, v1 — "Standalone alias accounts and their
members".

Every rule below carries the document that decides it. Anything this feature
needs and no document decides is in Open Questions, not in Business Rules.

## Purpose

Manage standalone alias accounts — addresses that only redirect, with no
mailbox behind them (`docs/00-overview.md` §6, `docs/02-domain.md` §6) — and
the set of addresses each one delivers to.

The alias account and its members live in **two different tables**. Presenting
them as one screen is the feature; conflating them in the data layer is the
defect this document exists to prevent.

## Actors

| Actor | Capability here |
|---|---|
| Global admin | Every alias on the server |
| Domain admin | Aliases whose `alias.domain` is one of their assigned domains |
| Mail user | None in v1 (`docs/policies/authorization.md` §1) |

`docs/policies/authorization.md` applies in full — the scope rule (§2),
query-level enforcement (§3), server-side authorization of every request (§4),
and audit of every write and every authorization failure (§7). It is not
restated here. The rules below only narrow it.

## Business Rules

- **BR-01** — A standalone alias account is a row in `alias`, primary key
  `address`. It has no `mailbox` row and no password; it only redirects
  (`docs/02-domain.md` §6, `docs/00-overview.md` §6).
- **BR-02** — Its members are **not** rows in `alias`. A member is a row in
  `forwardings` with `is_list = 1`, `address` = the alias address, `forwarding`
  = the destination address (`docs/02-domain.md` §6 and §5).
- **BR-03** — `forwardings` serves four unrelated purposes discriminated by
  flags, and the domain model must expose them as four distinct entities. This
  feature reads, writes and deletes **only** rows with `is_list = 1`. Rows
  carrying `is_forwarding`, `is_alias` or `is_maillist` are never listed,
  modified or deleted by it (`docs/02-domain.md` §5).
- **BR-04** — `forwardings` is unique on `(address, forwarding)`. An address is
  a member of a given alias at most once; a repeated submission never produces a
  second row (`docs/02-domain.md` §5, `docs/reference/schema-type-matrix.md` §4).
- **BR-05** — Member queries are anchored on `forwardings.address`, which is
  indexed on both drivers, and never filter on a flag alone — the flag indexes
  do not match between the two schema files
  (`docs/reference/schema-type-matrix.md` §4, D15).
- **BR-06** — Creation is bounded by `domain.aliases`, the domain's maximum
  number of alias accounts. The limit is checked before the insert, and `0`
  means unlimited, not "zero allowed" (`docs/02-domain.md` §2).
- **BR-07** — Every address accepted by this feature — the alias address and
  every member address — is trimmed and lower-cased in `prepareForValidation()`
  before validation, on writes and on look-ups alike
  (`docs/decisions/0005-lowercase-canonical-addresses.md`). Stated once; it
  applies to every endpoint in Contracts.
- **BR-08** — `alias.created`, `alias.modified` and `alias.expired` are written
  explicitly on every insert and update, with the sentinel value for the driver
  in use. The column default is never allowed to fire, and expiry is tested as
  `expired > now()`, never by equality against a sentinel
  (`docs/02-domain.md` §1.2; `docs/reference/schema-type-matrix.md` D1, D2).
  `forwardings` has no date columns.
- **BR-09** — `alias.active` and `forwardings.active` are `TINYINT(1)` on MySQL
  and `INT2` on PostgreSQL. Comparisons and writes bind `1`/`0`, never
  `true`/`false` (`docs/02-domain.md` §1.3;
  `docs/reference/schema-type-matrix.md` D4).
- **BR-10** — Deleting an alias account deletes its `is_list` member rows in the
  same transaction. No foreign key exists between the two tables, so orphans are
  the application's responsibility on delete (`docs/01-architecture.md` §3,
  "Consequences to design around"; `docs/02-domain.md` §6). What else, if
  anything, a deletion must record is BR-14: nothing beyond those two writes.
- **BR-11** — `alias.accesspolicy` is a free-text `VARCHAR(30)` with nothing
  constraining it at the schema level. Mailward neither validates it against a
  fixed set nor presents one until OQ-AL-01 is answered
  (`docs/reference/schema-type-matrix.md` §5 and Unverified item 10).
- **BR-12** — An alias account is a domain-owned resource; its domain for the
  scope rule is `alias.domain`. A member row is scoped by the alias it belongs
  to — `forwardings.address` is the alias address — and never by the member's
  own domain (`docs/policies/authorization.md` §2). This depends on how
  `forwardings.domain` is populated: OQ-AL-02.
- **BR-13** — Mailward issues DML only against `vmail`. No column, index or
  constraint is added to `alias` or `forwardings` to support this feature
  (`docs/01-architecture.md` §2,
  `docs/decisions/0002-separate-application-database.md`).
- **BR-14** — Deleting a standalone alias account requires nothing beyond the
  cascade in BR-10 and BR-18: the `alias` row, its `is_list` member rows, and
  the rows elsewhere that name the alias address as a destination, in one
  transaction (`docs/reference/decisions-needed.md` D1, Q6). There is **no**
  deletion record equivalent to `deleted_mailboxes` — an alias account owns no
  mail storage, so iRedMail's removal cron has nothing to do for it
  (`docs/02-domain.md` §11). No table in Mailward's own database is cleaned
  either, because none is keyed by an alias address; `audit_log` records the
  deletion and, being exempt from address-keyed cleanup, keeps its reference to
  the address afterwards (`docs/02-domain.md` §13,
  `docs/features/audit-log.md` BR-08). Deleting the alias's **domain** deletes the alias
  through this same cascade, in the domain deletion's transaction
  (`docs/features/domains.md` BR-16).
- **BR-15** — `domain.aliases` counts the rows this feature creates and only
  those: `alias` rows whose `domain` is that domain
  (`docs/reference/decisions-needed.md` D2; `docs/features/domains.md` BR-18).
  Per-account alias rows (`forwardings.is_alias`) and `alias_domain` rows do not
  consume the budget, so the count BR-06 compares against the limit is taken
  from `alias` alone and never from `forwardings` or `alias_domain`.
- **BR-16** — **The domain part of the alias address must already exist as a row
  in `domain`.** It is checked with an explicit `EXISTS` on the `vmail`
  connection, inside the same transaction as the insert, on the already
  lower-cased value (BR-07), and a failure is a validation error on the address
  field rather than a silent insert. `alias.domain` is then that same value,
  never a domain the server does not host. The rule catches the typo that
  otherwise produces an address nothing will ever deliver to, and it is the same
  rule `docs/02-domain.md` §3 already imposes on `alias_domain.target_domain`.
  **A row in `alias_domain` does not satisfy it**: an alias domain has no
  accounts of its own and its mail resolves to the target domain's accounts
  (`docs/02-domain.md` §3), so an `alias` row inside one would be shadowed by
  that mapping rather than reachable through it. Locality binds the alias
  address only; whether a **member** address is validated beyond format is a
  separate question and is still open — OQ-AL-04
  (`docs/reference/decisions-needed.md` Q4, answered 2026-08-15, option C).
- **BR-17** — **Collisions are permitted.** An `alias.address` may equal an
  existing `mailbox.username`, and may equal a `forwardings.address` of another
  kind. Mailward performs **no** cross-table uniqueness check against `mailbox`,
  `forwardings`, `domain` or `alias_domain`, and refuses no create on those
  grounds: such a check would have to fold case identically on both drivers
  (`docs/reference/schema-type-matrix.md` D17) and would forbid arrangements a
  running iRedMail may resolve sensibly. Which of the two wins at delivery is
  therefore a property of the mail server and not of Mailward; the probe that
  observes it (`docs/reference/decisions-needed.md` E7) is **informational
  rather than blocking**, because no rule here depends on its outcome. Where a
  collision is visible to Mailward — the address already exists as a mailbox —
  the interface may warn, but it may not refuse (same source, Q4 answered
  2026-08-15).
- **BR-18** — **Deleting an alias account also removes the rows that point *at*
  its address.** In the same transaction as BR-10, the deletion of alias `X`
  removes every `forwardings` row whose `forwarding` column is `X` — `X` as
  another account's forwarding target, and `X` as a member of a second alias
  account — leaving those other accounts and alias accounts themselves intact.
  Without this the alias address survives as a live routing target after the
  alias is gone: the configuration looks right in every listing and routes mail
  to an address that no longer accepts it. This makes the alias cascade
  symmetric with the mailbox cascade, which already removes inbound rows
  (`docs/features/mailboxes.md` BR-23, items 4 and 5). Rows carrying
  `is_maillist = 1` are not touched: mailing lists are unmodelled in v1
  (`docs/02-domain.md` §12) (`docs/reference/decisions-needed.md` Q6, answered
  2026-08-15).

## Data

Two tables in `vmail`, and the split between them is the point of the feature.

**`alias`** — the alias account. Primary key `address`
(`docs/02-domain.md` §6, `docs/reference/schema-type-matrix.md` §5).

| Column | Use here |
|---|---|
| `address` | The alias address, lower case. Primary key |
| `name` | Display label |
| `accesspolicy` | Free text, `VARCHAR(30)`, unconstrained — BR-11 |
| `domain` | Domain part; the scope key — BR-12 |
| `created`, `modified`, `expired` | Written explicitly, per driver — BR-08 |
| `active` | `1`/`0` — BR-09 |

**`forwardings`** — the members. Surrogate `id`, unique on
`(address, forwarding)` (`docs/02-domain.md` §5,
`docs/reference/schema-type-matrix.md` §4).

| Column | Value for a member of a standalone alias |
|---|---|
| `address` | The alias address |
| `forwarding` | The member address |
| `domain`, `dest_domain` | Population rule undecided — OQ-AL-02 |
| `is_list` | `1` |
| `is_forwarding`, `is_alias`, `is_maillist` | `0` |
| `active` | `1`/`0`; meaning for an `is_list` row undecided — OQ-AL-07 |

Read-only context: `domain.aliases` for the limit in BR-06, and `domain` itself
for the locality check in BR-16.

No table in Mailward's own database is required by this feature. There are no
foreign keys and no cross-database joins in either direction
(`docs/01-architecture.md` §3).

## Contracts

Inertia page routes and form endpoints. No JSON API — the panel never talks to
its own backend over one (`docs/decisions/0004-inertia-vue.md`).

`{address}` is the full lower-cased alias address, URL-encoded.
`{forwarding}` is the full lower-cased member address, URL-encoded.

| Method | Path | Page / effect |
|---|---|---|
| GET | `/aliases` | `Aliases/Index` — paginated `alias` rows within scope, with member counts |
| GET | `/aliases/create` | `Aliases/Create` |
| POST | `/aliases` | Create the `alias` row. Redirect to `Aliases/Show` |
| GET | `/aliases/{address}` | `Aliases/Show` — the alias and its `is_list` members |
| GET | `/aliases/{address}/edit` | `Aliases/Edit` |
| PUT | `/aliases/{address}` | Update `name`, `accesspolicy`, `active` |
| DELETE | `/aliases/{address}` | Delete the alias, its members and the rows naming it as a destination (BR-10, BR-18) |
| POST | `/aliases/{address}/members` | Add one member row (`is_list = 1`) |
| DELETE | `/aliases/{address}/members/{forwarding}` | Remove that member row only |

Input is validated in FormRequests, output shaped by Resources
(`docs/01-architecture.md` §7). Permission props sent to the frontend show and
hide controls only; the server authorizes every request again
(`docs/policies/authorization.md` §4).

The primary key is a string, so `PUT`/`DELETE` never change `address`. Renaming
an alias is out of scope — see Out of Scope.

## States

**Alias account** — derived, never stored as an enum in `vmail`
(`docs/01-architecture.md` §7: domain states are PHP enums, never database
enums).

| State | Condition |
|---|---|
| Active | `active = 1` and `expired > now()` |
| Inactive | `active = 0` |
| Expired | `expired <= now()` |

**Member** — present or absent. A member row's `active` column exists, but
whether anything honours it is OQ-AL-07, so v1 has no third member state.

## Acceptance Criteria

Each maps one-to-one to a test, and the suite runs against both MySQL and
PostgreSQL (`docs/01-architecture.md` §8).

- **AC-01** — given a global admin, when `GET /aliases`, then an Inertia
  response for `Aliases/Index` is returned (not JSON) listing rows from `alias`
  only, and no row from `forwardings` appears as an alias.
- **AC-02** — given a domain admin assigned only `example.com`, and aliases in
  `example.com` and `other.com`, when `GET /aliases`, then only the
  `example.com` aliases are returned and the exclusion is applied by the SQL
  query, asserted through the paginator total rather than the rendered page.
- **AC-03** — given input address `Sales@Example.COM`, when `POST /aliases`,
  then the stored `alias.address` is `sales@example.com`.
- **AC-04** — given a domain with `domain.aliases = 2` and two existing `alias`
  rows, when `POST /aliases` for that domain, then the request fails validation
  and no third row is inserted.
- **AC-05** — given a domain with `domain.aliases = 0` and any number of
  existing aliases, when `POST /aliases`, then the alias is created.
- **AC-06** — given an existing alias, when `POST /aliases/{address}/members`
  with `member@example.com`, then exactly one `forwardings` row exists with
  `address` = the alias address, `forwarding = member@example.com`,
  `is_list = 1`, and `is_forwarding = is_alias = is_maillist = 0`; and no row is
  added to `alias`.
- **AC-07** — given that member already exists, when the same `POST` is
  repeated, then the request fails validation and `forwardings` still holds
  exactly one row for that `(address, forwarding)` pair.
- **AC-08** — given a mailbox whose mandatory self-referencing row
  (`address = forwarding`, `is_forwarding = 1`) exists, and that mailbox is a
  member of an alias, when the member is removed via
  `DELETE /aliases/{address}/members/{forwarding}`, then only the `is_list` row
  is deleted and the mailbox's self-referencing row is unchanged.
- **AC-09** — given an alias with three members, when `DELETE /aliases/{address}`,
  then the `alias` row and all three `is_list` rows are gone, and any
  `forwardings` row with the same `address` but a different flag is unchanged.
- **AC-10** — given a freshly created alias, when the row is read back, then
  `created` and `modified` hold the value Mailward wrote rather than the column
  default, `expired` holds the never-expires sentinel for the driver in use, and
  the alias is returned by a listing filtered with `expired > now()`.
- **AC-11** — given a domain admin assigned only `example.com`, when they
  request `GET /aliases/{address}` for an alias in `other.com`, then the
  response is 403 and an authorization failure is recorded.
- **AC-12** — given an alias, when it is disabled through
  `PUT /aliases/{address}`, then `alias.active` is `0` and the same test passes
  on MySQL and on PostgreSQL with no boolean binding error.
- **AC-13** — given an alias whose `accesspolicy` is an arbitrary string, when
  it is saved and read back, then the value is stored unchanged and no
  validation rejects it (BR-11).
- **AC-14** — given an alias with two members, when `DELETE /aliases/{address}`
  succeeds, then no row was inserted into `deleted_mailboxes`, no row was
  written or removed in `panel_profiles` or `two_factor_secrets`, and the
  `audit_log` entry for the deletion still names the alias address (BR-14).
- **AC-15** — given a domain with `domain.aliases = 2`, one existing `alias`
  row, four `forwardings` rows with `is_alias = 1` and two `alias_domain` rows
  targeting it, when a second alias account is created it succeeds, and when a
  third is attempted it fails validation — the limit check counted `alias` rows
  only, and no executed statement counted `forwardings` or `alias_domain`
  (BR-15).
- **AC-16** — given no `domain` row for `nope.test`, when `POST /aliases` is
  submitted with `sales@nope.test`, then the request fails validation on the
  address, no `alias` row is inserted and no `forwardings` row is written; and
  given a `domain` row for `example.com`, when `sales@example.com` is submitted,
  then it is created (BR-16).
- **AC-17** — given `alias.test` exists only as an `alias_domain` row targeting
  `example.com`, when `POST /aliases` is submitted with `sales@alias.test`, then
  the request fails validation on the address and no row is inserted — an alias
  domain does not satisfy locality (BR-16).
- **AC-18** — given a mailbox `sales@example.com` already exists, when
  `POST /aliases` is submitted for the identical address, then the alias is
  created, both rows coexist, and no statement executed by the create queried
  `mailbox`, `forwardings` or `alias_domain` for a conflicting name (BR-17).
- **AC-19** — given alias `sales@example.com`, a mailbox `bob@example.com` whose
  `is_forwarding` row targets `sales@example.com`, a second alias
  `all@example.com` holding `sales@example.com` as an `is_list` member, and a
  row carrying `is_maillist = 1` that also names it, when
  `DELETE /aliases/sales@example.com` succeeds, then the first two of those rows
  are gone, `bob@example.com`, its self-referencing row and the `all@example.com`
  alias row all survive, and the `is_maillist` row is unchanged (BR-18).
- **AC-20** — given the same fixture, when the deletion runs, then every one of
  those deletes and the deletes of BR-10 executed inside a single `vmail`
  transaction, and re-running the deletion afterwards succeeds without error and
  removes nothing further (BR-14, BR-18).

## Out of Scope

- Per-user aliases (`forwardings.is_alias`) and mail user forwardings
  (`forwardings.is_forwarding`) — separate v1 features over the same table
  (`docs/02-domain.md` §5).
- mlmmj mailing lists and their members (`forwardings.is_maillist`),
  `maillists`, `moderators`, `maillist_owners` (`docs/00-overview.md` §5 Later,
  `docs/02-domain.md` §12).
- Alias domains — `alias_domain` is a different concept and a different feature
  (`docs/02-domain.md` §3).
- Renaming an alias. `address` is the primary key and is also referenced by
  every member row's `address`; nothing in `docs/` decides how a rename
  propagates.
- `alias.settings` — no such column; `settings` columns elsewhere belong to
  iRedAdmin-Pro and are neither read nor written (`docs/02-domain.md` §1.4).
- Self-service management of aliases by mail users
  (`docs/00-overview.md` §5 Later).

## Open Questions

- **OQ-AL-01** — What values may `alias.accesspolicy` take? It is free-text
  `VARCHAR(30)` in both schema files with nothing constraining it
  (`docs/reference/schema-type-matrix.md`, Unverified item 10). Which values
  does iRedMail write on a fresh install, which are honoured by Postfix or
  iRedAPD, and what does an empty string mean? Until answered, Mailward must not
  present a fixed list (BR-11).
- **OQ-AL-02** — How are `alias.domain`, `forwardings.domain` and
  `forwardings.dest_domain` populated on insert? Nothing in `docs/` states the
  rule. `alias.domain` is presumably the domain part of `address` and
  `dest_domain` that of `forwarding`, but this is not decided anywhere, and
  BR-12 — the authorization scope key — depends on it.
- **OQ-AL-04** — May a member address be outside the actor's administered
  domains, or outside the server entirely, and must a member inside a locally
  hosted domain correspond to an existing account? The scope rule
  (`docs/policies/authorization.md` §2) governs the resource's domain and says
  nothing about a forwarding destination. BR-16 settles locality for the
  **alias address** and deliberately leaves the member address open; any answer
  here must be consistent with BR-17 and **must not assume a collision is
  refused** — an address may legitimately be both a mailbox and an alias, so
  "the target exists" can be true of two different objects at once
  (`docs/reference/decisions-needed.md` Q17).
- **OQ-AL-07** — Is `forwardings.active = 0` honoured for an `is_list` row —
  can a member be disabled rather than removed? Nothing sourced says any
  iRedMail component reads it. The same doubt is recorded for
  `domain_admins.active` in `docs/reference/open-questions-research.md`,
  "Still unknown", OQ-02.
- **OQ-AL-08** — Must a standalone alias have at least one member? Nothing in
  `docs/` decides whether a member-less alias is a valid state to create or to
  leave behind after removing the last member.
