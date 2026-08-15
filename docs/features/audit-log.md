# Audit Log

Status: draft

Scope line: `docs/00-overview.md` §5, v1 — "Audit log of every write Mailward
performs".

Sources: `docs/01-architecture.md` §2, §3, §7, `docs/02-domain.md` §11, §13,
`docs/policies/authorization.md` §2, §4, §5, §6, §7,
`docs/decisions/0002-separate-application-database.md`,
`docs/decisions/0004-inertia-vue.md`,
`docs/decisions/0005-lowercase-canonical-addresses.md`,
`standards/security.md` §10.

---

## Purpose

Record what Mailward changed, who changed it, and what it looked like before and
after — plus every authorization failure, because a domain admin repeatedly
probing another domain's resources is exactly what the log exists to show
(`policies/authorization.md` §7).

The log is the only part of the product that is answerable after the fact. It
outlives the accounts it names: it lives in Mailward's own database, is
append-only, and deliberately keeps references to mailboxes that no longer exist
(`02-domain.md` §13).

## Actors

| Actor | Interaction |
|---|---|
| Every administrator | *writes* entries implicitly, by performing any write or by being denied one |
| Global admin | the **only** reader of the log in v1 (BR-16) |
| Domain admin | *writes* entries like any administrator; **reads none of them** (BR-16) |
| Mail user | none — cannot sign in (`policies/authorization.md` §1) |

`audit_log` is a Mailward-owned entity (`02-domain.md` §13), not a domain-owned
resource, so the scope rule of `policies/authorization.md` §2 does not reach it
as written. Rather than extend the scope rule to a resource it was not written
for, v1 puts the whole log behind the global-admin flag: BR-16.

## Business Rules

- **BR-01** — Every write Mailward performs is recorded
  (`00-overview.md` §5, `policies/authorization.md` §7). Whether "every write"
  extends to Mailward's own tables as well as the iRedMail databases is
  OQ-AUD-02.
- **BR-02** — An entry records the acting address, the action, the target, the
  before values, the after values, the request IP and the timestamp
  (`02-domain.md` §13, `policies/authorization.md` §7).
- **BR-03** — Authorization failures are recorded as entries in their own right,
  including the login gate's step-2 failures, which are recorded as
  authorization failures and not as authentication failures
  (`policies/authorization.md` §6, §7; `docs/features/authentication.md` BR-06).
- **BR-04** — **Passwords, password hashes and secrets are never recorded in
  before/after values.** `mailbox.password` is excluded from both sides of every
  entry, as are 2FA secrets and any other credential material. A password change
  records *that* the password changed, never the value or the hash on either
  side of it (`standards/security.md` §10; `docs/features/authentication.md`
  BR-17). This rule has no exception and no debug mode.
- **BR-05** — The log is **append-only**. No entry is updated or deleted by any
  code path, and no contract exists that would (`02-domain.md` §13).
- **BR-06** — The log lives in Mailward's own database and is created by an
  ordinary migration. Nothing is ever written to an iRedMail database on its
  behalf, and no column is added to `mailbox` to carry it
  (`01-architecture.md` §2, `decisions/0002`, `02-domain.md` §13).
- **BR-07** — References to mail accounts are plain address strings with no
  foreign key; cross-database constraints are impossible
  (`01-architecture.md` §3, `02-domain.md` §13).
- **BR-08** — Deleting a mailbox explicitly removes the Mailward rows that
  reference the address. **`audit_log` is the exception** and is never cleaned:
  it deliberately retains references to accounts that no longer exist
  (`02-domain.md` §13). Its entries remain listable after the target is gone.
- **BR-09** — Actor and target addresses are stored in the canonical lowercase
  form (`decisions/0005`, which applies to every address at the request
  boundary). An entry is otherwise stored exactly as recorded.
- **BR-10** — `action` is a PHP enum stored as a string. It is never a database
  enum (`01-architecture.md` §7).
- **BR-11** — For a mailbox deletion, the acting address recorded in the entry
  is the same address written to `deleted_mailboxes.admin` in that operation
  (`02-domain.md` §11, `docs/features/mailboxes.md` BR-16).
- **BR-12** — An authorization-failure entry carries the attempted action, the
  target and the actor, and no before/after values: nothing changed
  (`policies/authorization.md` §7 records the attempt, not a state change).
- **BR-13** — No query joins `audit_log` to a `vmail` table, and no listing
  opens a transaction spanning both databases. Where an entry's target needs
  enriching, it is correlated in PHP after the log side is filtered and
  paginated (`01-architecture.md` §3).
- **BR-14** — Reading the log is authorised on the server on every request, and
  the route fails closed. Any props describing what the reader may see are for
  UX only (`policies/authorization.md` §4). The rule that decides *who* may read
  is OQ-AUD-01, and the feature is not implementable before it is answered.
- **BR-15** — An entry recording a write is written for that write only. It is
  never a substitute for the operation succeeding, and the log is never read
  back as the source of truth for mail data — `vmail` is
  (`01-architecture.md` §2). Where the entry falls relative to the `vmail`
  commit is OQ-AUD-03.

## Data

**Owned — `audit_log`, in Mailward's database** (`02-domain.md` §13), created by
migration, append-only (BR-05):

| Field | Content |
|---|---|
| `id` | surrogate key |
| `occurred_at` | timestamp of the recorded event |
| `actor_address` | the acting mail address, canonical lower case (BR-09) |
| `action` | the enum value, stored as a string (BR-10) |
| `target_type` | what the target is — mailbox, domain, alias, alias domain, forwarding, domain-admin assignment, settings |
| `target_id` | the target's identifier: an address, or a domain name |
| `before` | structured values before the change; empty for a creation and for an authorization failure (BR-12) |
| `after` | structured values after the change; empty for a deletion and for an authorization failure |
| `ip` | the requesting IP address |

`target_type` is stored alongside `target_id` because an identifier alone does
not identify a row: a mailbox address and an alias address are the same shape,
and both are the same shape as a `forwardings.address` value
(`02-domain.md` §4, §5, §6).

`before` and `after` never contain `mailbox.password`, any password hash or any
secret (BR-04). They never contain `mailbox.settings` or `domain.settings`,
which Mailward neither reads nor writes (`02-domain.md` §1.4).

**Referenced, never joined** — `vmail.mailbox`, `vmail.domain`, `vmail.alias`,
`vmail.alias_domain`, `vmail.forwardings`, `vmail.domain_admins`: targets are
recorded as strings, with no foreign key and no cross-database join (BR-07,
BR-13).

**Not cleaned on delete** — every other Mailward table keyed by an address
(`panel_profiles`, `two_factor_secrets`) is cleaned when a mailbox is deleted;
`audit_log` is not (BR-08).

## Contracts

Inertia page only. No JSON API is exposed for the panel's own use
(`decisions/0004`).

| Method | Path | Page / result |
|---|---|---|
| GET | `/audit-log` | `AuditLog/Index` — paginated, most recent first; filters by actor, action, target and date range |

- Filter and sort inputs are validated against an allowlist of columns; no raw
  client-supplied column reaches a query (`standards/security.md` §8).
- **There is no POST, PUT, PATCH or DELETE contract for an audit entry**, and no
  route resolves for one (BR-05). Recording has no HTTP contract at all: it is a
  side effect of the write Actions and of the authorization layer.
- Access to `GET /audit-log` is authorised server-side (BR-14); the rule it
  enforces is OQ-AUD-01, so this contract is blocked until that is answered.

Error cases: an unauthenticated request is redirected to `/login`
(`docs/features/authentication.md` BR-18). A request for an entry outside the
reader's permitted view returns 403 — and is itself an authorization failure,
therefore itself recorded (BR-03), which is intentional.

## States

An entry has no lifecycle. That is the feature:

```
(none) --record--> recorded   (terminal)
```

- There is no edit, no correction, no soft delete and no retention transition
  (BR-05). A mistaken entry is answered by a later entry, never by changing the
  first.
- An entry's target may cease to exist; the entry does not change and remains
  listable (BR-08).
- Forbidden transitions: `recorded → updated`, `recorded → deleted`, and any
  cleanup pass that treats an entry as an orphan (`02-domain.md` §13).

## Acceptance Criteria

- **AC-01** — Given an administrator changes a mailbox quota, when the change
  succeeds, then exactly one entry exists carrying the acting address, the
  action, the target type and address, `before` holding the previous quota,
  `after` holding the new one, the request IP and a timestamp. *(BR-01, BR-02)*
- **AC-02** — Given an administrator changes a mailbox password, when the change
  succeeds, then an entry exists for the action and **no field of that entry
  contains the submitted password, the previous hash or the new hash**.
  *(BR-04)*
- **AC-03** — Given any entry produced by any write in v1, when every field is
  inspected, then no password, hash, 2FA secret or `settings` value appears in
  `before` or `after`. *(BR-04)*
- **AC-04** — Given a domain admin administering `a.com`, when they request a
  mailbox in `b.com` and receive 403, then an authorization-failure entry exists
  naming the actor, the attempted action, the target and the IP, with empty
  `before` and `after`. *(BR-03, BR-12)*
- **AC-05** — Given correct credentials for a mailbox with no admin flag, when
  sign-in is attempted, then an authorization-failure entry exists — not an
  authentication-failure entry. *(BR-03; `docs/features/authentication.md`
  AC-02)*
- **AC-06** — Given an existing entry, when the application's routes are
  enumerated, then no route resolves for updating or deleting it, and no code
  path issues an UPDATE or DELETE against `audit_log`. *(BR-05)*
- **AC-07** — Given a mailbox with entries in `audit_log` and rows in
  `panel_profiles` and `two_factor_secrets`, when the mailbox is deleted, then
  the latter two are removed, the entries remain, and they are still returned by
  the listing. *(BR-08)*
- **AC-08** — Given an actor or target address supplied in mixed case, when an
  entry is recorded, then both are stored in lower case. *(BR-09)*
- **AC-09** — Given a mailbox deletion, when it completes, then the entry's
  acting address equals the `admin` value written into `deleted_mailboxes` for
  the same operation. *(BR-11)*
- **AC-10** — Given a mailbox creation, when it completes, then at least one
  entry exists whose target is the new address and whose action is the creation.
  *(BR-01; granularity is OQ-AUD-04)*
- **AC-11** — Given a listing request, when the executed statements are
  inspected, then none joins `audit_log` to a `vmail` table and none opens a
  transaction spanning both connections. *(BR-13)*
- **AC-12** — Given an entry whose target address no longer exists in
  `vmail.mailbox`, when the listing renders, then the entry appears with its
  recorded address and the page raises no error. *(BR-07, BR-08)*
- **AC-13** — Given an unauthenticated request to `/audit-log`, when it is made,
  then it is redirected to `/login` and no entry is returned. *(BR-14)*
- **AC-14** — Given a filter or sort parameter naming a column outside the
  allowlist, when the request is made, then it is rejected and the parameter
  never reaches a query.
- **AC-15** — Given `action` values are written and read back, when the column
  is inspected in the database schema, then it is a string column and not a
  database enum. *(BR-10)*
- **AC-16** — Given the log is written to, when the connection used is
  inspected, then it is Mailward's own, and no statement is issued on the
  `vmail` connection on the log's behalf. *(BR-06)*

## Out of Scope

- Recording reads. The log records writes and authorization failures
  (`policies/authorization.md` §7); listing a page of mailboxes is neither.
- Reading iRedMail's own logs, Postfix's queue or Dovecot's logs — the log
  viewer is a later scope line (`00-overview.md` §5, Later).
- Any diff, rollback or "restore from audit" capability. The log is a record,
  not a backup; `vmail` is the source of truth for mail data (BR-15).
- Alerting, notification and rate-of-failure detection on top of the log.
- Export, archival and retention enforcement (OQ-AUD-06 raises the question;
  this feature does not implement one).
- Recording writes made by anything other than Mailward — iRedAdmin running
  alongside (`decisions/0003`), iRedMail's own cron jobs
  (`01-architecture.md` §6), and direct SQL are invisible to this log and must
  not be presented as covered by it.
- `mailbox.settings` and `domain.settings` in any form
  (`02-domain.md` §1.4).

## Open Questions

- **OQ-AUD-01** — **Who may read the audit log, and under what scope?**
  `policies/authorization.md` §2 scopes domain-owned resources; `audit_log` is a
  Mailward-owned entity (`02-domain.md` §13) and no policy assigns it a scope.
  Three sub-questions, none decided in `docs/`: is reading restricted to global
  admins; may a domain admin read entries whose target lies in their domains;
  and what happens to entries with no domain-scoped target — sign-in failures,
  settings changes, domain-admin assignments — which cannot be scoped by domain
  at all. BR-14 cannot be implemented until this is answered.
- **OQ-AUD-02** — Does "every write Mailward performs" cover writes to
  Mailward's **own** tables — `settings`, `panel_profiles`,
  `two_factor_secrets` — or only writes to the iRedMail databases? Read
  literally it also covers `sessions`, `cache` and `jobs`, which would make the
  log unusable. `00-overview.md` §5 and `policies/authorization.md` §7 both say
  "every write" without drawing the line.
- **OQ-AUD-03** — Where does the entry fall relative to the `vmail` commit?
  `01-architecture.md` §3 forbids a transaction spanning both databases and
  orders the `vmail` write last. So the entry is written either before the
  operation is known to have succeeded — recording a write that may not have
  happened — or after it can no longer be rolled back, risking a completed write
  with no entry. Which failure is accepted is not decided, and it decides the
  log's evidentiary value.
- **OQ-AUD-04** — Is an entry recorded per business operation or per row
  written? Creating a mailbox writes `mailbox` **and** its self-referencing
  `forwardings` row in one transaction (`02-domain.md` §5); deleting one writes
  `mailbox`, `deleted_mailboxes` and Mailward-side cleanups. One entry per
  operation reads better; one per row is more faithful.
- **OQ-AUD-05** — Are failed writes recorded — a validation rejection, a driver
  error, a limit refused by `02-domain.md` §2, a change blocked by BR-A01 of
  `policies/authorization.md` §5? Only authorization failures are stated
  explicitly (§7). An attempt to delete the last global admin is arguably the
  single most interesting thing the log could hold, and nothing decides whether
  it is held.
- **OQ-AUD-06** — Is the log ever pruned, archived or capped? `02-domain.md` §13
  makes it append-only and exempt from orphan cleanup, and sets no retention. On
  a busy install it grows without bound, and any pruning rule would be the one
  operation that contradicts BR-05.
- **OQ-AUD-07** — Is append-only enforced at the database, by denying UPDATE and
  DELETE on `audit_log` to Mailward's own database user, as
  `01-architecture.md` §3 does for the `vmail` connection ("enforcement, not
  convention")? That connection is also the one migrations run on, so the same
  technique does not transfer unchanged.
- **OQ-AUD-08** — What is recorded as actor and IP for a write made outside a
  web request? `php artisan mailward:promote <address> --global`
  (`policies/authorization.md` §5, BR-A04) writes to `vmail` with no session and
  no IP, and it is the documented escape hatch precisely for the moments an
  audit reader would most want to see. Whether it is audited at all, and under
  what actor, is undecided.
