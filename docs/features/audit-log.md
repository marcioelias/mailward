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
outlives the accounts it names: it lives in Mailward's own database, is never
modified, and deliberately keeps references to mailboxes that no longer exist
(`02-domain.md` §13). It does **not** outlive them indefinitely — retention is a
configurable window with a scheduled prune, defaulting to "never prune" (BR-05,
BR-21).

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
  (`00-overview.md` §5, `policies/authorization.md` §7). "Every write" is not
  read literally — the tables and events it covers are enumerated in BR-17, and
  the events it deliberately excludes in BR-18.
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
- **BR-05** — **No entry is ever modified, and entries are never deleted
  individually; entries older than the configured retention window are removed
  wholesale by a scheduled job.** No code path updates an entry, and no code
  path deletes one selectively — not by id, not by actor, not by target, not by
  action. The only `DELETE` that exists anywhere against `audit_log` is the
  age-based prune of BR-21, whose predicate is the entry's age and nothing else.
  This is a weaker guarantee than the append-only one this rule previously
  stated, and the weakening is deliberate: **the log's evidentiary value now has
  a horizon.** An entry older than the window is gone, and anything that must
  outlive the window has to be exported before the prune reaches it — which v1
  does not do for you, because export is out of scope (`02-domain.md` §13;
  `docs/reference/decisions-needed.md` Q11, answered 2026-08-15).
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
  UX only (`policies/authorization.md` §4). The rule it enforces is BR-16.
- **BR-15** — An entry recording a write is written for that write only. It is
  never a substitute for the operation succeeding, and the log is never read
  back as the source of truth for mail data — `vmail` is
  (`01-architecture.md` §2). Where the entry falls relative to the `vmail`
  commit is OQ-AUD-03.
- **BR-16** — **Reading the audit log is global-admin only.** `GET /audit-log`
  requires `mailbox.isglobaladmin = 1`; a domain admin cannot read the log at
  all, not even the entries whose target lies inside a domain they administer,
  and not even the entries they themselves produced. The refusal is a `403` and
  is itself an authorization failure, therefore itself recorded (BR-03, BR-12).
  The cost is stated rather than hidden: a domain admin cannot see who probed
  their own domain, which is one of the purposes this log is given in Purpose —
  in v1 that question is answered by asking a global admin. The benefit is that
  no target → domain resolver is needed for any `target_type`, and no rule is
  needed for the entries that have no domain at all — sign-ins, `settings`
  changes, domain-admin assignments, console runs (BR-20). Widening this later
  is purely additive (`docs/reference/decisions-needed.md` Q3, answered
  2026-08-15).
- **BR-17** — **A recorded write is exactly one of four things**, and the list
  is closed (`docs/reference/decisions-needed.md` Q8, answered 2026-08-15):
  1. any write to `vmail` — `mailbox`, `domain`, `alias`, `alias_domain`,
     `forwardings`, `domain_admins`, `deleted_mailboxes`;
  2. any write to Mailward's own `settings` table — the panel's own
     configuration, including the retention window of BR-21;
  3. a **successful sign-in** (`docs/features/authentication.md` BR-19). It is
     neither a `vmail` write nor a failure, and it is recorded because a sign-in
     timeline is what an audit reader looks for beside the failures;
  4. a **deliberate refusal** — the last-global-admin block of
     `policies/authorization.md` BR-A01, a per-domain limit refusal
     (`02-domain.md` §2), and any authorization denial (BR-03). A refusal is
     recorded because the intent is the evidence: an attempt to delete the last
     global admin is the single most interesting thing this log can hold.
  The line is drawn where an administrator's mental model already draws it — the
  mail data, plus the panel's own configuration.
- **BR-18** — **Not recorded**, and this is a decision rather than an omission
  (same source): driver errors, which are an operational fault and belong in the
  application log; validation rejections, because a mistyped form is not an
  attempt at anything and recording them makes volume the log's problem instead
  of the reader's; and every write to `panel_profiles`, `sessions`, `cache` and
  `jobs`, which are the panel's own machinery and carry no administrative
  meaning. A successful sign-in writes `panel_profiles`
  (`docs/features/authentication.md` BR-15) and still produces exactly **one**
  entry — the sign-in of BR-17, never a second one for the profile row.
- **BR-19** — **One entry per business operation, not one per row written**
  (`docs/reference/decisions-needed.md` Q9, answered 2026-08-15). Creating a
  mailbox writes `mailbox` and its self-referencing `forwardings` row and
  produces one entry; deleting a domain writes dozens of rows through the
  cascade of `docs/features/domains.md` BR-16 and produces one entry. The rows
  the operation affected are summarised in the `before`/`after` payload, which
  is therefore structured rather than a copy of one row's columns — and for a
  cascade the payload **carries the counts it removed**, per table, so the entry
  names what it destroyed rather than merely that it destroyed something. No
  correlation id exists or is needed: the entry *is* the operation.
- **BR-20** — A write made **outside a web request** — the recovery command
  `php artisan mailward:promote <address> --global`
  (`policies/authorization.md` BR-A04), a scheduled job, any console invocation
  — is audited under a **sentinel actor** rather than a mail address, and the
  `ip` field carries the **OS user and hostname** of the invocation in place of
  an IP address, because that is the only identity the invocation actually has
  (`docs/reference/decisions-needed.md` Q10, answered 2026-08-15). The sentinel
  is never a real address, so no entry ever claims that the promoted account
  promoted itself, and console runs are distinguishable from web actions by
  inspection of the actor alone. The escape hatch is the moment an audit reader
  most wants visibility, so it is the one invocation that must never be
  unrecorded.
- **BR-21** — **Retention is a configurable window, enforced by a scheduled
  prune** (`docs/reference/decisions-needed.md` Q11, answered 2026-08-15). The
  window is an instance setting, held in Mailward's own `settings` table and
  therefore itself audited (BR-17). A value meaning **never prune must exist,
  and it is the default**: an install that is never configured keeps every entry
  for ever, and no upgrade silently starts deleting evidence. When a window is
  configured, a scheduled command deletes every entry older than it, in one
  pass, reading no column but the entry's age (BR-05). The prune has no HTTP
  contract, is not triggered by any request, and is never partial or selective.
  Package defaults do not decide this: any retention default shipped by a
  logging library is overridden explicitly to "never prune", so the guarantee is
  Mailward's and not a dependency's.

## Data

**Owned — `audit_log`, in Mailward's database** (`02-domain.md` §13), created by
migration, never modified and never deleted from except by the age-based prune
of BR-21 (BR-05):

| Field | Content |
|---|---|
| `id` | surrogate key |
| `occurred_at` | timestamp of the recorded event |
| `actor_address` | the acting mail address, canonical lower case (BR-09) |
| `action` | the enum value, stored as a string (BR-10) |
| `target_type` | what the target is — mailbox, domain, alias, alias domain, forwarding, domain-admin assignment, settings |
| `target_id` | the target's identifier: an address, or a domain name |
| `before` | structured values before the change, summarising every row the operation affected, with per-table counts for a cascade (BR-19); empty for a creation and for an authorization failure (BR-12) |
| `after` | structured values after the change (BR-19); empty for a deletion and for an authorization failure |
| `ip` | the requesting IP address — or, for a write made outside a web request, the OS user and hostname of the invocation (BR-20) |

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
| GET | `/audit-log` | `AuditLog/Index` — paginated, most recent first; filters by actor, action, target and date range. **Global admins only** (BR-16) |

- Filter and sort inputs are validated against an allowlist of columns; no raw
  client-supplied column reaches a query (`standards/security.md` §8).
- **There is no POST, PUT, PATCH or DELETE contract for an audit entry**, and no
  route resolves for one (BR-05). Recording has no HTTP contract at all: it is a
  side effect of the write Actions and of the authorization layer. Neither does
  pruning: the retention job of BR-21 is scheduled, never requested.
- Access to `GET /audit-log` is authorised server-side against
  `mailbox.isglobaladmin` (BR-14, BR-16). A domain admin receives `403`, and
  that refusal is itself recorded.

Error cases: an unauthenticated request is redirected to `/login`
(`docs/features/authentication.md` BR-18). A request from an administrator who
is not a global admin returns 403 — and is itself an authorization failure,
therefore itself recorded (BR-03, BR-16), which is intentional.

## States

An entry has one transition out of `recorded`, and it is driven by the clock
rather than by anything an administrator does:

```
(none) --record--> recorded --age past the retention window--> pruned  (terminal)
```

- There is no edit, no correction and no soft delete (BR-05). A mistaken entry
  is answered by a later entry, never by changing the first.
- `pruned` is reached only by the scheduled job of BR-21, only on age, and only
  when a retention window is configured — under the default, "never prune", no
  entry ever leaves `recorded`.
- An entry's target may cease to exist; the entry does not change and remains
  listable (BR-08).
- Forbidden transitions: `recorded → updated`; `recorded → deleted` by any path
  other than the age-based prune, including any cleanup pass that treats an
  entry as an orphan of a deleted account (`02-domain.md` §13, BR-08).

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
  enumerated, then no route resolves for updating or deleting it, no code path
  issues an UPDATE against `audit_log`, and the only DELETE any code path issues
  against it is the scheduled prune of BR-21. *(BR-05)*
- **AC-07** — Given a mailbox with entries in `audit_log` and rows in
  `panel_profiles` and `two_factor_secrets`, when the mailbox is deleted, then
  the latter two are removed, the entries remain, and they are still returned by
  the listing. *(BR-08)*
- **AC-08** — Given an actor or target address supplied in mixed case, when an
  entry is recorded, then both are stored in lower case. *(BR-09)*
- **AC-09** — Given a mailbox deletion, when it completes, then the entry's
  acting address equals the `admin` value written into `deleted_mailboxes` for
  the same operation. *(BR-11)*
- **AC-10** — Given a mailbox creation, when it completes, then exactly one
  entry exists whose target is the new address and whose action is the creation,
  even though the operation wrote both a `mailbox` row and a `forwardings` row.
  *(BR-01, BR-19)*
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
- **AC-17** — Given a domain admin with a live grant on `a.com` and entries in
  the log whose target is a mailbox in `a.com`, when they request
  `/audit-log`, then the response is 403, no entry is serialised into any prop,
  and an authorization-failure entry is recorded naming them. *(BR-16, BR-03)*
- **AC-18** — Given a global admin, when they request `/audit-log`, then the
  listing is returned and it includes entries with no domain-scoped target at
  all — a sign-in, a `settings` change, a domain-admin assignment and a console
  run — none of which is filtered out or requires a target → domain resolution.
  *(BR-16)*
- **AC-19** — Given correct credentials for an account with `isadmin = 1`, when
  the sign-in succeeds, then exactly one entry exists for it, and no second
  entry exists for the `panel_profiles` row the same request wrote.
  *(BR-17, BR-18)*
- **AC-20** — Given the retention window is changed through the panel, when the
  change succeeds, then an entry exists for the `settings` write; and given a
  request writes `sessions`, `cache` or `jobs` and nothing else, then no entry
  is written for it. *(BR-17, BR-18)*
- **AC-21** — Given exactly one global admin, when a request attempts to delete
  it and is refused by BR-A01, then an entry exists recording the refusal — the
  actor, the attempted action and the target — with an empty `after`; and given
  a create refused by a per-domain limit, then an entry exists for that refusal
  too. *(BR-17)*
- **AC-22** — Given a create request that fails validation, and given a write
  that fails with a driver error, when each completes, then `audit_log` is
  byte-for-byte unchanged and the failure appears only in the application log.
  *(BR-18)*
- **AC-23** — Given a domain holding two mailboxes, one standalone alias with
  two members and one alias domain, when the domain is deleted, then exactly one
  entry exists for the whole cascade, and its payload names the counts removed
  per table — two mailboxes, one alias, two members, one alias domain — rather
  than one entry per row. *(BR-19)*
- **AC-24** — Given `php artisan mailward:promote user@example.com --global` is
  run from a shell, when it completes, then an entry exists whose actor is the
  sentinel and not `user@example.com`, whose `ip` field holds the invoking OS
  user and hostname rather than an IP address, and which is distinguishable from
  a web-originated promotion by the actor alone. *(BR-20)*
- **AC-25** — Given the same promotion performed through the panel by a signed-in
  global admin, when it completes, then the entry's actor is that
  administrator's address and its `ip` field holds the request IP — the sentinel
  appears in neither. *(BR-20)*
- **AC-26** — Given a fresh install whose retention window has never been
  configured, when the scheduled prune runs against a log containing entries ten
  years old, then no entry is deleted: the default is "never prune". *(BR-21)*
- **AC-27** — Given a retention window of 90 days and entries at 89 and 91 days
  old, when the scheduled prune runs, then the 91-day entry is gone, the 89-day
  entry remains, no entry was updated, and the executed DELETE filtered on age
  alone — no actor, action or target appears in its predicate. *(BR-21, BR-05)*
- **AC-28** — Given a library or package default that would prune the log on its
  own schedule, when the application boots, then that default is overridden to
  "never prune", asserted against the effective configuration rather than the
  shipped file. *(BR-21)*

## Out of Scope

- Recording reads. The log records writes and authorization failures
  (`policies/authorization.md` §7); listing a page of mailboxes is neither.
- Reading iRedMail's own logs, Postfix's queue or Dovecot's logs — the log
  viewer is a later scope line (`00-overview.md` §5, Later).
- Any diff, rollback or "restore from audit" capability. The log is a record,
  not a backup; `vmail` is the source of truth for mail data (BR-15).
- Alerting, notification and rate-of-failure detection on top of the log.
- **Export and archival.** Retention *is* implemented (BR-21), and export is
  not — which is the sharp edge of BR-05: once a window is configured, evidence
  that must outlive it has no supported way out of the table, and getting it out
  before the prune reaches it is an operator's problem in v1.
- Recording writes made by anything other than Mailward — iRedAdmin running
  alongside (`decisions/0003`), iRedMail's own cron jobs
  (`01-architecture.md` §6), and direct SQL are invisible to this log and must
  not be presented as covered by it.
- `mailbox.settings` and `domain.settings` in any form
  (`02-domain.md` §1.4).

## Open Questions

- **OQ-AUD-03** — Where does the entry fall relative to the `vmail` commit?
  `01-architecture.md` §3 forbids a transaction spanning both databases and
  orders the `vmail` write last. So the entry is written either before the
  operation is known to have succeeded — recording a write that may not have
  happened — or after it can no longer be rolled back, risking a completed write
  with no entry. Which failure is accepted is not decided, and it decides the
  log's evidentiary value.
- **OQ-AUD-07** — Is append-only enforced at the database, by denying UPDATE and
  DELETE on `audit_log` to Mailward's own database user, as
  `01-architecture.md` §3 does for the `vmail` connection ("enforcement, not
  convention")? That connection is also the one migrations run on, so the same
  technique does not transfer unchanged. Note that any such grant must still
  permit the age-based prune of BR-21, which is a `DELETE`.
