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
authorize the owning account.

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
for validation, including the duplicate-pair check of BR-07; 422 for any attempt
to target the self-referencing row (BR-06).

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
- Deleting these rows as part of deleting the owning mailbox — undecided, see
  OQ-A7 and `docs/features/mailboxes.md` OQ-M4.
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
- **OQ-A4** — Must an alias address belong to a domain that exists in
  `vmail.domain` (or in `vmail.alias_domain`), and must that domain be one the
  actor administers? `02-domain.md` §3 states the "target domain must exist" rule
  for alias domains only; nothing states it for a per-user alias address.
- **OQ-A5** — Does the `domain.aliases` limit count per-user alias rows in
  `forwardings`, or only standalone alias accounts in `vmail.alias`?
  `02-domain.md` §2 describes the column as "max alias accounts" and requires
  the limit to be enforced before creating an account, without saying which
  rows count.
- **OQ-A6** — Does v1 expose per-row enable/disable through
  `forwardings.active`, or only create and delete? The column exists and
  defaults to `1`; the v1 scope line says only "per-user aliases and
  forwardings".
- **OQ-A7** — Must deleting the owning mailbox delete these rows? Same question
  as `docs/features/mailboxes.md` OQ-M4: `02-domain.md` §13 decides the cleanup
  for Mailward's own database only, and there are no foreign keys inside `vmail`
  either.
- **OQ-A8** — Is a forwarding target validated beyond address format — for
  instance, must a target inside a locally hosted domain correspond to an
  existing account, and are self-referencing or circular targets between two
  local mailboxes rejected? Nothing in `docs/` decides it.
