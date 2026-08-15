# Per-User Aliases and Forwardings

Status: draft

Sources: `docs/00-overview.md` §5 (v1: *"Per-user aliases and forwardings"*),
`docs/02-domain.md` §5, §12, §13, `docs/01-architecture.md` §3,
`docs/policies/authorization.md`, `docs/reference/schema-type-matrix.md` §4,
`docs/decisions/0004`, `0005`. Depends on `docs/features/mailboxes.md`.

---

## Purpose

Manage the two per-account address behaviours a mailbox owner needs: extra
addresses that deliver to the account (**aliases**, `forwardings.is_alias = 1`)
and copies of the account's mail sent on to another address (**forwardings**,
`forwardings.is_forwarding = 1`).

Both are rows in the same physical table. That table serves four unrelated
purposes discriminated by flags, and `02-domain.md` §5 requires the domain model
to expose them as distinct concepts rather than as one "forwardings" screen.
This document covers two of the four; the other two are named in Out of Scope.

## Actors

| Actor | May act on |
|---|---|
| Global admin | Aliases and forwardings of every mailbox |
| Domain admin | Only those of mailboxes in the domains they administer |

Mail users have no access in v1 (`docs/policies/authorization.md` §1); managing
one's own forwardings is a later, self-service feature (`00-overview.md` §5).

`docs/policies/authorization.md` applies in full and is not restated here.

## Business Rules

- **BR-01** — Aliases and forwardings are two distinct concepts sharing one
  table. They are presented and routed separately, never merged into a single
  "forwardings" screen (`02-domain.md` §5).
- **BR-02** — Every row is owned by a mailbox. The owning mailbox is resolved
  first, and authorization is decided on it: the resource's domain is the owning
  mailbox's `domain`, per the scope rule of `docs/policies/authorization.md` §2,
  enforced in the query (§3 of that policy). The destination domain of a
  forwarding plays no part in the authorization decision.
- **BR-03** — Every address accepted by this feature — the alias address, the
  forwarding target, the owning mailbox address — is trimmed and lowercased at
  the request boundary
  (`docs/decisions/0005-lowercase-canonical-addresses.md`, which names
  forwardings explicitly). Stated once here; it applies to every input and every
  lookup below.
- **BR-04** — A row written by this feature sets exactly one of `is_forwarding`
  and `is_alias` to `1`, and leaves `is_list` and `is_maillist` at `0`
  (`02-domain.md` §5).
- **BR-05** — Rows with `is_list = 1` or `is_maillist = 1` are never listed,
  edited or deleted by this feature. They belong to standalone alias accounts
  and to mlmmj mailing lists respectively (`02-domain.md` §5, §12).
- **BR-06** — The self-referencing row of every mailbox —
  `address = forwarding =` the account's address, `is_forwarding = 1` — is an
  invariant of the mailbox, not a user-managed forwarding. It is created with
  the mailbox (`docs/features/mailboxes.md` BR-04), is excluded from the
  forwardings listing, and is never deleted or deactivated by this feature: a
  mailbox without it appears correct in every listing and receives no mail
  (`02-domain.md` §5).
- **BR-07** — `(address, forwarding)` is unique on both drivers. The pair is
  checked by the application against the canonical lowercase form before insert;
  the database unique index is not relied upon, because it catches case
  duplicates on MySQL and misses them on PostgreSQL (matrix D17, `0005`
  Consequences).
- **BR-08** — `is_forwarding`, `is_alias`, `is_list`, `is_maillist` and `active`
  are written and compared as `1`/`0`, never as `true`/`false` (`02-domain.md`
  §1.3, matrix D4). Rows are created with `active = 1`.
- **BR-09** — Every query against `forwardings` is anchored on `address` or
  `domain` and never filters on a flag alone: MySQL has no index on
  `is_forwarding` (matrix D15).
- **BR-10** — A row is addressed by its `id`, which is a primary key on both
  drivers, but the two drivers generate it from different sequence types —
  64-bit unsigned auto-increment on MySQL, 32-bit `SERIAL` on PostgreSQL — so
  nothing assumes a comparable id range or width across installs (matrix D9).
  Every lookup by `id` is additionally constrained by the owning mailbox's
  `address`.
- **BR-11** — Every write performed by this feature, and every authorization
  failure against it, is recorded per `docs/policies/authorization.md` §7.
- **BR-12** — No transaction spans both databases and no query joins across
  them; the `vmail` write is the last commit and is idempotent on retry
  (`01-architecture.md` §3).
- **BR-13** — `vmail` receives DML only. No column, index or constraint is added
  to `forwardings` to make any of the above easier
  (`01-architecture.md` §2, `docs/decisions/0002`).
- **BR-14** — Deleting the owning mailbox deletes every row this feature
  manages for it — an explicit cascade, since `forwardings` has no foreign key
  to the `mailbox` row (`docs/reference/decisions-needed.md` D1;
  `docs/features/mailboxes.md` BR-23). It reaches the account's `is_alias` rows,
  its `is_forwarding` rows, the self-referencing row of BR-06, every row
  elsewhere in the table whose `forwarding` column is that address, and every
  `is_list` row making it a member of a standalone alias. The write belongs to
  the mailbox deletion, in that deletion's `vmail` transaction; **no endpoint in
  Contracts performs it**. What this feature gains is the invariant: no row it
  lists can outlive its owning mailbox, so no listing has to tolerate a row
  whose owner is gone.
- **BR-15** — A row written by this feature counts against **no** per-domain
  limit. `domain.aliases` bounds standalone alias accounts (`vmail.alias`) only
  (`docs/reference/decisions-needed.md` D2; `docs/features/domains.md` BR-18),
  so per-account aliases and forwardings are unbounded in v1. This feature
  therefore performs no limit check before an insert, and that absence is a
  decision, not an omission.
- **BR-16** — **The domain part of a per-account alias address must already
  exist as a row in `domain`**, checked with an explicit `EXISTS` on the `vmail`
  connection inside the same transaction as the insert, on the already
  lower-cased value (BR-03). The alias address names an address this server is
  expected to accept, so an address in a domain the server does not host is a
  typo that produces a silently dead address; `02-domain.md` §3 already imposes
  the same rule on `alias_domain.target_domain`. A row in `alias_domain` does
  not satisfy it, because an alias domain has no accounts of its own
  (`02-domain.md` §3). Two things this rule deliberately does **not** do. It
  does not constrain a **forwarding target**: a forwarding is a destination,
  frequently external, and whether a target is validated beyond format is
  OQ-A8. And it does not refuse a **collision** — the alias address may equal an
  existing `mailbox.username` or an existing `alias.address`, and no cross-table
  uniqueness check is performed, so which object wins at delivery is a property
  of the mail server rather than of Mailward. The probe that observes it
  (`docs/reference/decisions-needed.md` E7) is informational rather than
  blocking. The *authorization* half was already settled by BR-02 and is
  unchanged: the decision is made on the owning mailbox's domain, never on the
  destination's (`docs/reference/decisions-needed.md` Q4, answered 2026-08-15,
  option C).
- **BR-17** — Deleting a **standalone alias account** deletes the rows in this
  table that name the alias address in their `forwarding` column — the alias as
  a mailbox's forwarding target, and the alias as a member of a second alias
  account — in that deletion's `vmail` transaction. The write belongs to
  `docs/features/aliases.md` BR-18; **no endpoint in Contracts performs it**,
  and this feature gains the same invariant BR-14 gives it for mailboxes: no row
  it lists can name a destination that has already been deleted, so no listing
  has to render a target that is gone. It is the symmetric counterpart of item 4
  of `docs/features/mailboxes.md` BR-23
  (`docs/reference/decisions-needed.md` Q6, answered 2026-08-15).

## Data

**Written** — `vmail.forwardings`, surrogate key `id`, unique on
`(address, forwarding)`:

| Column | Use in this feature |
|---|---|
| `id` | Row identity for edit/delete (BR-10) |
| `address` | See OQ-A1 |
| `forwarding` | See OQ-A1 |
| `domain` | NOT NULL, `''` default on both drivers — see OQ-A2 |
| `dest_domain` | NOT NULL, `''` default on both drivers — see OQ-A2 |
| `is_forwarding` | `1` for a forwarding, `0` otherwise (BR-04) |
| `is_alias` | `1` for an alias, `0` otherwise (BR-04) |
| `is_list`, `is_maillist` | Always `0` on rows this feature writes; rows carrying `1` are invisible to it (BR-05) |
| `active` | `1` on create (BR-08) |

The table has no date columns, so the sentinel-date rules of `02-domain.md`
§1.2 do not apply here.

**Read-only** — `vmail.mailbox` (`username`, `domain`, `active`) to resolve and
authorize the owning account, and `vmail.domain` for the locality check of
BR-16.

**Not touched** — `vmail.alias` (standalone alias accounts) and every row of
`forwardings` discriminated by `is_list` or `is_maillist`.

## Contracts

Inertia pages and form endpoints only; no JSON API for the panel's own use
(`docs/decisions/0004-inertia-vue.md`). `{mailbox}` is the URL-encoded canonical
address of the owning account; `{id}` is `forwardings.id`, always resolved
together with `{mailbox}` (BR-10). Validation lives in FormRequests.

| Method | Path | Page / result |
|---|---|---|
| GET | `/mailboxes/{mailbox}/aliases` | `Mailboxes/Aliases/Index` — the account's `is_alias` rows |
| POST | `/mailboxes/{mailbox}/aliases` | Creates one `is_alias` row |
| PUT | `/mailboxes/{mailbox}/aliases/{id}` | Updates that row |
| DELETE | `/mailboxes/{mailbox}/aliases/{id}` | Deletes that row |
| GET | `/mailboxes/{mailbox}/forwardings` | `Mailboxes/Forwardings/Index` — the account's `is_forwarding` rows, excluding the self-referencing row (BR-06) |
| POST | `/mailboxes/{mailbox}/forwardings` | Creates one `is_forwarding` row |
| PUT | `/mailboxes/{mailbox}/forwardings/{id}` | Updates that row |
| DELETE | `/mailboxes/{mailbox}/forwardings/{id}` | Deletes that row |

Both listings may also be rendered as sections of the mailbox page, provided
they remain two separately labelled concepts with separate endpoints (BR-01).

Authorization props are for showing and hiding controls only; the server
authorizes every request again (`docs/policies/authorization.md` §4).

Error cases: 403 when the owning mailbox is outside the actor's scope, or when
`{id}` does not belong to `{mailbox}` (logged per BR-11); 422 with field errors
for validation, including the duplicate-pair check of BR-07 and the locality
check of BR-16 on an alias address; 422 for any attempt to target the
self-referencing row (BR-06).

## States

A row has two states, `active = 1` and `active = 0`, plus absence.

```
(none) --create--> active <--> inactive        (see OQ-A6)
   ^                  |            |
   +-----delete-------+------------+
```

- Rows are created `active = 1` (BR-08).
- Deletion is a hard delete of that one row; nothing else about the mailbox
  changes.
- Forbidden: deleting or deactivating the self-referencing row of the owning
  mailbox (BR-06); creating a second row with the same `(address, forwarding)`
  pair (BR-07); writing a row with more than one discriminator flag set
  (BR-04).
- Whether the `active` transition is exposed in v1 at all is OQ-A6.

## Acceptance Criteria

- **AC-01** — Given a valid forwarding is created, when the row is written, then
  it has `is_forwarding = 1`, `is_alias = 0`, `is_list = 0` and
  `is_maillist = 0`.
- **AC-02** — Given a valid alias is created, when the row is written, then it
  has `is_alias = 1`, `is_forwarding = 0`, `is_list = 0` and `is_maillist = 0`.
- **AC-03** — Given a mailbox with its self-referencing row and one user-created
  forwarding, when the forwardings listing renders, then it contains exactly one
  entry and the self-referencing row is not in it.
- **AC-04** — Given a DELETE or PUT targeting the id of the self-referencing
  row, when it is submitted, then the request is rejected and the row still
  exists unchanged with `is_forwarding = 1` and `active = 1`.
- **AC-05** — Given rows for the same address with `is_list = 1` and with
  `is_maillist = 1`, when the alias and forwarding listings render, then neither
  row appears in either listing, and neither can be deleted through this
  feature's endpoints.
- **AC-06** — Given an address entered with mixed case, when the row is written,
  then both `address` and `forwarding` hold the lowercase form.
- **AC-07** — Given a row for `(a@x.com, b@y.com)` exists, when the same pair is
  submitted differing only in case, then the request fails validation with a
  duplicate error, on MySQL **and** on PostgreSQL.
- **AC-08** — Given a domain admin administering `a.com` only, when they request
  any endpoint of this feature for a mailbox in `b.com`, then the response is
  403 and an authorization failure is recorded.
- **AC-09** — Given a row id belonging to a different mailbox, when it is
  submitted against `{mailbox}`, then the response is 403 or 404 and the row is
  unchanged.
- **AC-10** — Given either listing runs, when the executed query is inspected,
  then it is constrained by `address` (or `domain`) and never by a discriminator
  flag alone.
- **AC-11** — Given either listing or a create runs on PostgreSQL, when the
  query executes, then it completes without a type error: flags and `active` are
  bound as `1`/`0`.
- **AC-12** — Given a mailbox with several aliases and forwardings, when one row
  is deleted, then exactly that row is gone and every other row of the same
  mailbox — including the self-referencing row — is untouched.
- **AC-13** — Given any create, update or delete in this feature, when it
  succeeds, then an `audit_log` entry exists with the acting address, the
  action, the target and before/after values.
- **AC-14** — Given the same operations run on both drivers, when the resulting
  rows are compared, then they are identical in every column this feature
  writes.
- **AC-15** — Given `user@a.com` with two `is_alias` rows, one `is_forwarding`
  row, its self-referencing row, one row owned by another mailbox whose
  `forwarding` is `user@a.com`, and one `is_list` membership, when the mailbox
  is deleted, then none of those six rows remains and every endpoint of this
  feature for `user@a.com` responds 404 (BR-14).
- **AC-16** — Given a domain with `domain.aliases = 1` and one standalone
  `alias` row already in it, when ten per-account aliases are created for a
  mailbox in that domain, then all ten succeed and no statement executed by any
  of the creates counts rows for a limit check (BR-15).
- **AC-17** — Given a mailbox in `a.com` and no `domain` row for `nope.test`,
  when a per-account alias `sales@nope.test` is submitted for it, then the
  request fails validation on the alias address and no `forwardings` row is
  written; when `sales@a.com` is submitted instead, then the row is created
  (BR-16).
- **AC-18** — Given a mailbox in `a.com` and `alias.test` existing only as an
  `alias_domain` row, when a per-account alias `sales@alias.test` is submitted,
  then it fails validation; and given the same mailbox, when a **forwarding** to
  `someone@external.example` is submitted — a domain with no `domain` row — then
  it succeeds, because BR-16 binds alias addresses and not forwarding targets
  (BR-16).
- **AC-19** — Given a standalone alias `sales@a.com`, a mailbox
  `bob@a.com` whose `is_forwarding` row targets it, and an `is_list` row making
  `sales@a.com` a member of `all@a.com`, when the **alias account** is deleted,
  then both of those rows are gone, `bob@a.com` and its self-referencing row are
  untouched, and every listing of this feature for `bob@a.com` renders without a
  dangling destination (BR-17).

## Out of Scope

- **`is_list` — membership of a standalone alias account.** It is the same
  physical table and a different concept (`02-domain.md` §5, §6); it belongs to
  the standalone alias accounts feature, together with `vmail.alias` itself.
- **`is_maillist` — mlmmj mailing list membership.** Mailing lists are not in
  v1 at all (`00-overview.md` §5, Later; `02-domain.md` §12), so no row carrying
  this flag is created, read, changed or deleted here.
- The self-referencing row's creation, which belongs to mailbox creation
  (`docs/features/mailboxes.md` BR-04). This feature only preserves it.
- Self-service management by the mail user (`00-overview.md` §5, Later).
- Vacation / auto-reply, BCC maps and sender-dependent relayhost
  (`00-overview.md` §5, `02-domain.md` §12).
- Alias domains, which redirect a whole domain rather than one address
  (`02-domain.md` §3).
- Deleting these rows as part of deleting the owning mailbox. The cascade is
  decided (BR-14), but the write belongs to `docs/features/mailboxes.md` BR-23,
  not to any endpoint here.
- Bulk import and bulk edit.

## Open Questions

- **OQ-A1** — For a row with `is_alias = 1`, which column holds the alias
  address and which holds the owning mailbox: is it `address` = alias and
  `forwarding` = mailbox, or the reverse? `02-domain.md` §5 names the concept
  ("a per-account alias address") without stating the direction, and the same
  question applies to confirming that a forwarding is stored as
  `address` = mailbox, `forwarding` = destination. Getting it backwards produces
  rows that look right and route mail the wrong way. Nothing in `docs/` decides
  it; it must be read off a real install.
- **OQ-A2** — How are `domain` and `dest_domain` populated for these rows?
  `02-domain.md` §5 lists both columns without semantics, and both are NOT NULL
  with an `''` default on both drivers (matrix §4). In particular: what
  `dest_domain` holds when the target is an external domain, and whether
  `domain` always mirrors the owning mailbox's domain.
- **OQ-A3** — May the self-referencing row ever be absent or inactive, to
  express "forward without keeping a local copy"? `02-domain.md` §5 states the
  row is mandatory for every mailbox, so BR-06 forbids removing it; nothing in
  `docs/` decides whether such an option is expected to exist, and offering it
  would contradict that invariant.
- **OQ-A6** — Does v1 expose per-row enable/disable through
  `forwardings.active`, or only create and delete? The column exists and
  defaults to `1`; the v1 scope line says only "per-user aliases and
  forwardings".
- **OQ-A8** — Is a forwarding target validated beyond address format — for
  instance, must a target inside a locally hosted domain correspond to an
  existing account, and are self-referencing or circular targets between two
  local mailboxes rejected? Nothing in `docs/` decides it. BR-16 settles
  locality for the alias **address** and deliberately leaves the forwarding
  **target** open, so this question is now available to be answered either way.
  Any answer must **not assume a collision is refused** (BR-16): an address may
  legitimately be both a `mailbox` row and an `alias` row, so "the local target
  exists" can be true of two different objects at once, and an existence check
  must state which tables it consults (`docs/reference/decisions-needed.md`
  Q17).
