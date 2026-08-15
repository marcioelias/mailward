# Domain Admins

Status: draft

Scope line: `docs/00-overview.md` §5, v1 — "Domain admins: promote, demote,
assign domains".

Every rule below carries the document that decides it. Anything this feature
needs and no document decides is in Open Questions, not in Business Rules.

## Purpose

Promote a mail account to administrator, demote it, and assign or remove the
domains a domain admin may administer.

Administrators are ordinary mail accounts flagged in `mailbox` — `isadmin` and
`isglobaladmin`. There is no separate panel account
(`docs/decisions/0003-reuse-iredmail-admin-model.md`,
`docs/00-overview.md` §4). This feature therefore never creates an account; it
changes flags on an existing `mailbox` row and rows in `domain_admins`.

It is also the feature that can lock the organisation out of its own panel. The
lockout rules in `docs/policies/authorization.md` §5 are load-bearing here and
are referenced, never re-derived.

## Actors

| Actor | Capability here |
|---|---|
| Global admin | Promote, demote, assign and remove domains, on any account |
| Domain admin | Sees the `domain_admins` rows for the domains they administer (`docs/policies/authorization.md` §2). Writes none of them — BR-15 |
| Mail user | None in v1 (`docs/policies/authorization.md` §1) |

`docs/policies/authorization.md` applies in full — the scope rule (§2),
query-level enforcement (§3), server-side authorization (§4), the lockout rules
(§5), the login gate (§6) and audit (§7). It is not restated here. The rules
below only narrow it.

## Business Rules

### Inherited, referenced by their existing identifiers

These are defined in `docs/policies/authorization.md` §5 and keep their IDs.
This feature is where all four are enforced.

- **BR-A01** — The last global admin cannot be demoted, deactivated or deleted;
  the check runs inside the same transaction as the change.
- **BR-A02** — An administrator cannot revoke their own global admin flag.
- **BR-A03** — Deleting a domain removes its `domain_admins` rows; an
  administrator left with no domains and no global flag still logs in and sees
  an empty state. Domain deletion itself belongs to the domains feature; this
  feature must render that empty state.
- **BR-A04** — The recovery command
  `php artisan mailward:promote <address> --global` exists outside the web
  interface and is runnable by anyone with shell access. Its contract is
  specified under Contracts.

### Specific to this feature

- **BR-01** — Promotion targets an existing `mailbox` row. There is no
  create-administrator flow, and the legacy `admin` table is never read or
  written (`docs/decisions/0003-reuse-iredmail-admin-model.md`,
  `docs/02-domain.md` §8).
- **BR-02** — `domain_admins` has composite primary key `(username, domain)`.
  An account is assigned to a given domain at most once, and assigning it again
  is idempotent rather than an error (`docs/02-domain.md` §7,
  `docs/reference/schema-type-matrix.md` §6).
- **BR-03** — `mailbox.isglobaladmin = 1` is the authoritative input to the
  authorization decision, including the count in BR-A01. Rows in
  `domain_admins` scope a domain admin
  (`docs/policies/authorization.md` §1).
- **BR-04** — Promoting to global admin writes **both**
  `mailbox.isglobaladmin = 1` and a `domain_admins` row whose `domain` is the
  literal sentinel `'ALL'`; demoting removes both. Both writes happen in one
  transaction, together with the BR-A01 check. Source: iRedMail's own
  documentation records the promotion as two writes
  (`docs/reference/open-questions-research.md`, OQ-02, "What is established").
  **Status: sourced, not yet confirmed against a live install** — the
  verification procedure in that document has not been run, and the write path
  changes if it is refuted.
- **BR-05** — Every query that joins `domain_admins` to `domain` excludes
  `domain_admins.domain = 'ALL'`. Without the exclusion a global admin's domain
  list silently returns zero rows, because the sentinel occupies the same column
  as real domain names and matches no row in `domain`
  (`docs/reference/open-questions-research.md`, OQ-02, Hypothesis 5). Any query
  of the form `domain_admins JOIN domain ON domain_admins.domain = domain.domain`
  is suspect by construction.
- **BR-06** — On MySQL, `domain_admins.username` and `domain_admins.domain` are
  `CHARACTER SET ascii` while `domain.domain` is `utf8mb4`, so joining them
  raises an illegal-mix-of-collations error unless one side is explicitly
  converted. The scope query is written with that conversion and is tested on
  MySQL specifically (`docs/reference/schema-type-matrix.md` D8;
  `docs/02-domain.md` §7).
- **BR-07** — A consequence of the same charset: an internationalised address or
  IDN domain cannot be stored in `domain_admins` on MySQL at all. Such input is
  rejected at validation rather than written and truncated
  (`docs/reference/schema-type-matrix.md` D8).
- **BR-08** — Every address and domain accepted by this feature — including
  domain admin assignments and the artisan command's argument — is trimmed and
  lower-cased in `prepareForValidation()` before validation, on writes and on
  look-ups alike
  (`docs/decisions/0005-lowercase-canonical-addresses.md`, which names domain
  admin assignments explicitly). Stated once; it applies to every endpoint in
  Contracts. The `'ALL'` sentinel is not an address and is not subject to this
  rule; its stored case is OQ-DA-05.
- **BR-09** — `domain_admins.created`, `modified` and `expired` are written
  explicitly on every insert, with the sentinel for the driver in use; the
  column default is never allowed to fire, and expiry is tested as
  `expired > now()` (`docs/02-domain.md` §1.2;
  `docs/reference/schema-type-matrix.md` D1, D2, §6).
- **BR-10** — `domain_admins.active` and the `mailbox` flags are `TINYINT(1)` on
  MySQL and `INT2` on PostgreSQL; comparisons and writes bind `1`/`0`, never
  `true`/`false` (`docs/02-domain.md` §1.3;
  `docs/reference/schema-type-matrix.md` D4). New rows are written `active = 1`.
  An inactive or expired grant confers nothing (BR-10).
- **BR-11** — Setting or clearing `mailbox.isglobaladmin` is not a domain-owned
  operation. `docs/policies/authorization.md` §2 — the only authorization
  concept in the product — provides no domain under which a domain admin could
  be authorized for it, so it is available to global admins only. This narrows
  the policy; it does not extend it.
- **BR-12** — Mailward issues DML only against `vmail`. No column, index or
  constraint is added to `mailbox` or `domain_admins`
  (`docs/01-architecture.md` §2,
  `docs/decisions/0002-separate-application-database.md`).
- **BR-13** — Panel state that iRedMail has no place for — last panel login,
  preferences, 2FA — lives in Mailward's own database keyed by email address,
  never as a flag on `mailbox` (`docs/02-domain.md` §13,
  `docs/decisions/0003-reuse-iredmail-admin-model.md`). Demotion does not remove
  those rows — BR-14.
- **BR-15** — **Every write in this feature is global-admin only**, including
  assigning and removing an administrator on a domain the actor administers. A
  domain admin sees the grants covering their own domains and changes none of
  them. The reason is that v1 offers no way to review or revoke a grant a
  domain admin made — no notification, and a lapsed grant is removed rather
  than suspended (Q1) — so authority that propagates itself has no path back.
  Opening this later is additive; closing it later breaks a workflow
  administrators have come to rely on
  (`docs/reference/decisions-needed.md` Q2, answered 2026-08-15).
- **BR-14** — **Demotion is not a deletion.** The `mailbox` row survives, and so
  does everything keyed by its address (`docs/reference/decisions-needed.md`
  D1). A demotion writes, in one transaction with the BR-A01 check, only what
  its form covers: `DELETE /admins/{address}/global` clears
  `mailbox.isglobaladmin` and removes the `'ALL'` row (BR-04);
  `DELETE /admins/{address}/domains/{domain}` removes that one `domain_admins`
  row; a full demotion removes the account's `domain_admins` rows **and** clears
  `isadmin` (BR-16). It touches **no** row in Mailward's own
  database — not `panel_profiles`, not `two_factor_secrets` — because the
  account still exists, still receives mail, and may be promoted again, at which
  point that state must still be there. Those rows are removed only when the
  **mailbox** itself is deleted (`docs/features/mailboxes.md` BR-18) or when its
  domain is deleted (`docs/features/domains.md` BR-17).
- **BR-16** — **A full demotion clears `mailbox.isadmin`.** "Demoted" therefore
  means "no longer has the panel", not "has the panel and can see nothing in
  it": `DELETE /admins/{address}` removes the account's `domain_admins` rows and
  writes `isadmin = 0` in the same transaction as the BR-A01 check, after which
  the account fails step 2 of the login gate
  (`docs/policies/authorization.md` §6) exactly as a mail user does. Leaving the
  flag set would produce an administrator who signs in and sees nothing, which
  is a support ticket rather than a state: BR-A03's empty state exists for the
  *domain was deleted* case, and is not a demotion target. The account itself is
  untouched — it still exists, still receives mail, and may be promoted again
  (BR-14) (`docs/reference/decisions-needed.md` Q7, answered 2026-08-15).
- **BR-17** — **`--global` is a mandatory flag on `mailward:promote`, and there
  is no per-domain form of the command.** Invoked without it, the command
  refuses and writes nothing; it accepts no `--domain=` option and grants no
  per-domain administration. Per-domain grants are made through
  `POST /admins/{address}/domains` and nowhere else. The command exists to
  repair a lockout (BR-A04), and it is trustworthy in that role precisely
  because it does one thing: a general-purpose grant tool reachable by anyone
  with shell access is a larger surface than the escape hatch requires
  (`docs/reference/decisions-needed.md` Q7, answered 2026-08-15).
- **BR-18** — A run of `mailward:promote` is recorded in `audit_log` under a
  **sentinel actor**, not under the promoted address, with the invoking **OS
  user and hostname** recorded in place of the request IP
  (`docs/features/audit-log.md` BR-20). A shell invocation has no authenticated
  administrator and no IP, and the OS user is the only identity it actually has;
  recording the target as the actor would make the log claim that the promoted
  account promoted itself. The entry is what makes the escape hatch visible
  afterwards, and it is distinguishable from a web-originated promotion by the
  actor alone (`docs/reference/decisions-needed.md` Q10, answered 2026-08-15).
- **BR-19** — **When the two global-admin representations have drifted, Mailward
  reports it and changes nothing.** Drift is `mailbox.isglobaladmin = 1` with no
  `(username, 'ALL')` row in `domain_admins`, or that row present with the flag
  cleared; the two are written independently by iRedMail's own tooling and by
  anything else touching `vmail`, so they can disagree without Mailward having
  done anything. Three parts:
  1. **The authorization decision is unaffected and is not re-opened.** BR-03
     already settles it: `mailbox.isglobaladmin` is the authoritative input,
     including for the BR-A01 count, and the `'ALL'` row is excluded from every
     join (BR-05). A drifted pair therefore cannot change who may do what.
  2. **The drift is reported in the health check** — the same operator-facing
     scan that carries the unverifiable-scheme finding
     (`docs/features/authentication.md` BR-20), listing each address and which of
     the two representations is missing. It is a read: the check issues no write
     on any connection.
  3. **Mailward never repairs it, and never writes a `vmail` row during a read.**
     Silently inserting or deleting a `domain_admins` row to make the two agree
     would be a write nobody requested, performed during a `GET`, outside any
     audited action — contradicting `docs/policies/authorization.md` §7, which
     requires every write to be a recorded act with an actor behind it. The other
     panel administering this server would also see an administrator appear or
     vanish with no entry in either product's log. Reporting keeps the decision
     with the administrator, who is the only party that knows which of the two
     was intended.
  A repair remains available through the ordinary endpoints —
  `POST /admins/{address}/global` writes both representations and is idempotent
  (BR-04, AC-05), `DELETE /admins/{address}/global` removes both — so the finding
  is actionable in one click and the action is audited like any other. **Moot if
  E1 refutes the sentinel row** (`docs/reference/decisions-needed.md` E1,
  OQ-DA-09): if the installer writes no `'ALL'` row there is only one
  representation and nothing can drift, and this rule and the check it feeds are
  both dropped rather than reworded
  (`docs/reference/decisions-needed.md` Q24, answered 2026-08-15, option A).

## Data

**`vmail.mailbox`** — read and written for two columns only
(`docs/02-domain.md` §4, `docs/reference/schema-type-matrix.md` §3).

| Column | Use here |
|---|---|
| `username` | The account address, primary key |
| `isadmin` | Administrator flag; gates login (`docs/policies/authorization.md` §6) |
| `isglobaladmin` | Global admin flag; authoritative for the role (BR-03) |
| `active`, `expired` | Read only here; they deny access at both steps of §6 |

**`vmail.domain_admins`** — composite primary key `(username, domain)`
(`docs/02-domain.md` §7, `docs/reference/schema-type-matrix.md` §6).

| Column | Use here |
|---|---|
| `username` | Admin address. `CHARACTER SET ascii` on MySQL — BR-06, BR-07 |
| `domain` | Real domain name, or the sentinel `'ALL'` for a global admin — BR-04, BR-05 |
| `created`, `modified`, `expired` | Written explicitly, per driver — BR-09 |
| `active` | Written `1`. A row with `0`, or a past `expired`, confers nothing — BR-10 |

**`vmail.domain`** — read only, to list assignable domains and to resolve the
scope. Joined only with the `'ALL'` exclusion (BR-05) and the collation
conversion (BR-06).

**Mailward's own database** — `audit_log` records every write and every
authorization failure (`docs/policies/authorization.md` §7,
`docs/02-domain.md` §13). References to mail accounts are plain address
strings; there are no foreign keys and no cross-database joins
(`docs/01-architecture.md` §3).

## Contracts

Inertia page routes and form endpoints. No JSON API
(`docs/decisions/0004-inertia-vue.md`).

`{address}` is the full lower-cased account address, URL-encoded.
`{domain}` is the lower-cased domain name.

| Method | Path | Page / effect |
|---|---|---|
| GET | `/admins` | `Admins/Index` — accounts with `isadmin = 1` or `isglobaladmin = 1`, within scope, each with its administered domains (BR-05, BR-06) |
| GET | `/admins/create` | `Admins/Create` — pick an existing mailbox to promote |
| POST | `/admins` | Promote: set `isadmin = 1`; optionally assign initial domains |
| GET | `/admins/{address}` | `Admins/Show` — flags and assigned domains |
| DELETE | `/admins/{address}` | Demote: remove the `domain_admins` rows and clear `isadmin` (BR-16); subject to BR-A01 |
| POST | `/admins/{address}/global` | Grant global: `isglobaladmin = 1` **and** the `'ALL'` row (BR-04). Global admins only (BR-11) |
| DELETE | `/admins/{address}/global` | Revoke global: clear the flag **and** the `'ALL'` row. Refused by BR-A01 and BR-A02 |
| POST | `/admins/{address}/domains` | Assign one domain: insert `(address, domain)` (BR-02, BR-09) |
| DELETE | `/admins/{address}/domains/{domain}` | Remove that assignment only; never the `'ALL'` row |

Every write runs in one transaction that also contains the BR-A01 check.
Permission props sent to the frontend show and hide controls only; the server
authorizes every request again (`docs/policies/authorization.md` §4).

### Console: `mailward:promote`

The escape hatch required by BR-A04. It exists precisely so that a lockout
caused by the web interface can be repaired without the web interface.

```
php artisan mailward:promote <address> --global
```

| Element | Contract |
|---|---|
| `<address>` | Required. The mail account to promote. Trimmed and lower-cased before use (BR-08) |
| `--global` | **Mandatory** (BR-17). Promote to global admin — the form BR-A04 documents, and the only form. Omitting it is an error, not a per-domain promotion; there is no `--domain=` option |

**Validates, in this order, before any write**

1. `--global` is present (BR-17). Without it the command exits non-zero with one
   explanatory line and writes nothing — it does not fall back to a per-domain
   grant, because no such form exists.
2. The address is syntactically an address and is representable in ASCII
   (BR-07); otherwise it cannot be stored on MySQL.
3. A `mailbox` row exists with that `username`. If not, the command fails
   without writing.

It deliberately does **not** require the account to be active, unexpired, or
already an administrator — a recovery tool that refuses to run on a broken
install is not a recovery tool. It never accepts or prints a password.

**Writes**, in one transaction: `mailbox.isadmin = 1`,
`mailbox.isglobaladmin = 1`, and the `domain_admins` row
`(<address>, 'ALL')` (BR-04). The write is idempotent — re-running it on an
account that is already a global admin succeeds and does not raise a duplicate
primary key error, which is the failure iRedMail's own documented SQL produces
(`docs/reference/open-questions-research.md`, OQ-02, the forum report). BR-A01
and BR-A02 do not constrain it: it only ever grants.

**Prints**: the resolved lower-cased address; the before and after values of
`isadmin`, `isglobaladmin` and the presence of the `'ALL'` row; and one
confirmation line. Exit code `0` on success — including the no-op re-run —
non-zero with a single explanatory line when `--global` is absent (BR-17), when
the mailbox does not exist, or when the address is invalid.

**Access**: no panel permission gates it. Anyone with shell access to the
server can run it, by design (`docs/policies/authorization.md` §5, BR-A04);
shell access to the mail server is already sufficient to edit `vmail` directly.
It still writes an `audit_log` entry (§7), under the sentinel actor and with the
invoking OS user and hostname in place of the IP (BR-18).

## States

An account's administrative state is derived from `vmail`, never stored as an
enum there (`docs/01-architecture.md` §7).

| State | `isadmin` | `isglobaladmin` | `domain_admins` |
|---|---|---|---|
| Not an administrator | 0 | 0 | no rows |
| Domain admin | 1 | 0 | one row per assigned domain |
| Domain admin without domains | 1 | 0 | no rows — logs in, empty state (BR-A03) |
| Global admin | 1 | 1 | a `'ALL'` row (BR-04) |

"Domain admin without domains" is reached only by a **domain** being deleted
(BR-A03), never by a demotion: demotion clears `isadmin` and lands the account
in "Not an administrator" (BR-16).

**Inconsistent** — `isglobaladmin = 1` without the `'ALL'` row, or the `'ALL'`
row without the flag. The two representations are written independently by
iRedMail's tooling and can drift
(`docs/reference/open-questions-research.md`, OQ-02, Hypothesis 3). For the
authorization decision BR-03 settles it: the flag wins. What Mailward does about
the drift it finds is BR-19: it reports it in the health check and changes
nothing — the state is observable, never silently repaired, and it is not a
transition this feature drives.

Transitions to and from these states are the endpoints in Contracts. Login
access across all of them follows `docs/policies/authorization.md` §6.

## Acceptance Criteria

Each maps one-to-one to a test, and the suite runs against both MySQL and
PostgreSQL (`docs/01-architecture.md` §8).

- **AC-01** — given a global admin, when `GET /admins`, then an Inertia response
  for `Admins/Index` is returned listing every account with `isadmin = 1` or
  `isglobaladmin = 1`.
- **AC-02** — given a global admin whose `domain_admins` rows are exactly one
  `'ALL'` row, and three domains exist, when their administered-domain list is
  rendered, then it contains all three domains and not zero rows (BR-05).
- **AC-03** — given a MySQL connection, when the domain-scope query joining
  `domain_admins` to `domain` runs, then it executes without an
  illegal-mix-of-collations error (BR-06). This test is meaningful on MySQL
  specifically.
- **AC-04** — given an existing mailbox that is not an administrator, when
  `POST /admins/{address}/global`, then `mailbox.isadmin = 1`,
  `mailbox.isglobaladmin = 1` and exactly one `domain_admins` row
  `(address, 'ALL')` exist, all committed together.
- **AC-05** — given that account is already a global admin, when the same
  request is repeated, then no duplicate-key error occurs and exactly one
  `'ALL'` row still exists.
- **AC-06** — given two global admins, when one revokes the other's global flag
  via `DELETE /admins/{address}/global`, then both `mailbox.isglobaladmin = 0`
  and the `'ALL'` row is gone.
- **AC-07** — given exactly one account with `isglobaladmin = 1`, when a demote,
  deactivate or delete is attempted on it, then the request is refused, the
  transaction is rolled back, and `isglobaladmin` and the `'ALL'` row are
  unchanged (BR-A01).
- **AC-08** — given a global admin acting on their own account, when they
  request `DELETE /admins/{address}/global` for themselves, then the request is
  refused even though another global admin exists (BR-A02).
- **AC-09** — given an administrator and an existing domain, when
  `POST /admins/{address}/domains`, then exactly one `domain_admins` row exists
  for `(address, domain)` with `active = 1` and with `created`, `modified` and
  `expired` holding the values Mailward wrote rather than the column defaults.
- **AC-10** — given that assignment exists, when the same request is repeated,
  then there is still exactly one row and the response is not a server error
  (BR-02).
- **AC-11** — given a global admin who also administers `example.com` through a
  real row, when `DELETE /admins/{address}/domains/example.com`, then only that
  row is removed and the `'ALL'` row is untouched.
- **AC-12** — given input address `Admin@Example.COM` and domain `Example.COM`,
  when the assignment is stored, then `domain_admins` holds
  `admin@example.com` and `example.com` (BR-08).
- **AC-13** — given an address or domain containing a non-ASCII character, when
  it is submitted to any endpoint in Contracts, then it fails validation and no
  write is attempted against `domain_admins` (BR-07).
- **AC-14** — given a domain admin (not global), when they call
  `POST /admins/{address}/global`, then the response is 403, no flag changes,
  and an authorization failure is recorded (BR-11,
  `docs/policies/authorization.md` §7).
- **AC-15** — given an existing mailbox, when
  `php artisan mailward:promote user@example.com --global` runs, then the exit
  code is 0, the output shows the before and after values of both flags and the
  `'ALL'` row, and all three writes are present.
- **AC-16** — given no mailbox with that address, when the same command runs,
  then the exit code is non-zero, one explanatory line is printed, and neither
  `mailbox` nor `domain_admins` is written.
- **AC-17** — given the command has already run once for an account, when it
  runs a second time, then the exit code is 0 and exactly one `(address, 'ALL')`
  row exists.
- **AC-18** — given an administrator whose only domain is deleted, when they log
  in, then access is granted and an empty state is rendered rather than an error
  (BR-A03).
- **AC-20** — Given a domain admin administering `example.test`, when they
  assign another administrator to it, then the response is `403`, no
  `domain_admins` row is written, and the refusal is recorded as an
  authorization failure.
- **AC-21** — Given the same actor, when they remove an existing grant on a
  domain they administer, then the response is `403` and the grant survives.
- **AC-19** — given a global admin with a `panel_profiles` row, a
  `two_factor_secrets` row and one real per-domain `domain_admins` row, and
  given a second global admin exists, when the account is demoted through
  `DELETE /admins/{address}/global` and then
  `DELETE /admins/{address}/domains/{domain}`, then `isglobaladmin` is `0`, the
  `'ALL'` row and the per-domain row are gone, the `mailbox` row still exists
  with `active` unchanged, and both Mailward-side rows are byte-for-byte
  unchanged; when the account is promoted again, the same `two_factor_secrets`
  secret is still in force (BR-14).
- **AC-22** — given an administrator with `isadmin = 1` and two `domain_admins`
  rows, and given they are not the last global admin, when
  `DELETE /admins/{address}` succeeds, then `mailbox.isadmin` is `0`, both
  `domain_admins` rows are gone, the `mailbox` row still exists with `active`
  unchanged, and a subsequent sign-in with the account's correct password is
  denied at step 2 with the generic message (BR-16,
  `docs/features/authentication.md` AC-02).
- **AC-23** — given that demoted account, when it is promoted again through
  `POST /admins`, then `isadmin` is `1`, it signs in successfully, and its
  `panel_profiles` and `two_factor_secrets` rows are the same rows as before the
  demotion — byte-for-byte unchanged throughout (BR-16, BR-14).
- **AC-24** — given an existing mailbox, when
  `php artisan mailward:promote user@example.com` runs **without** `--global`,
  then the exit code is non-zero, one explanatory line is printed, and neither
  `mailbox` nor `domain_admins` is written — in particular no per-domain grant
  is created (BR-17).
- **AC-25** — given the command's definition, when its options are enumerated,
  then `--global` is required and no `--domain` option exists; and when
  `--domain=example.com` is passed, then the invocation fails as an unknown
  option and nothing is written (BR-17).
- **AC-26** — given `php artisan mailward:promote user@example.com --global`
  runs and succeeds, when `audit_log` is inspected, then exactly one entry
  exists for it, its actor is the sentinel rather than `user@example.com` or any
  other address, and its IP field holds the invoking OS user and hostname
  (BR-18, `docs/features/audit-log.md` BR-20).
- **AC-27** — given `admin@example.com` with `isglobaladmin = 1` and no
  `(admin@example.com, 'ALL')` row, and given `other@example.com` with that row
  present and `isglobaladmin = 0`, when the health check runs, then it reports
  both addresses and names which representation is missing for each; and when
  every statement the check issued is inspected, then none is an INSERT, UPDATE
  or DELETE on any connection (BR-19).
- **AC-28** — given that same fixture, when `GET /admins`, `GET /admins/{address}`
  and any other read path in this feature are exercised, then no `domain_admins`
  row is inserted or removed and no `mailbox` flag is changed — both accounts are
  in exactly the drifted state afterwards; `admin@example.com` is treated as a
  global admin and `other@example.com` is not, per BR-03; and when
  `POST /admins/other@example.com/global` is then issued by a permitted actor,
  the drift is resolved by that explicit, audited write and one `audit_log` entry
  exists for it (BR-19, BR-03, BR-04).

## Out of Scope

- Mailbox CRUD itself — creating the account being promoted is the mailboxes
  feature (`docs/00-overview.md` §5).
- Deleting a domain and the cascade of its `domain_admins` rows — that write
  belongs to the domains feature (`docs/features/domains.md` BR-16); BR-A03 only
  requires this feature to render the resulting empty state.
- Deleting a **mailbox** and the cascade of the `domain_admins` rows keyed by
  its address — that write belongs to the mailboxes feature
  (`docs/features/mailboxes.md` BR-27, BR-23 item 6,
  `docs/reference/decisions-needed.md` Q6, answered 2026-08-15). It matters here
  only as an invariant this feature can rely on: no `domain_admins` row names an
  account that no longer exists, so a re-created address never inherits the
  grants of the account it replaced.
- The legacy `admin` table. It is not how administrators are defined today and
  Mailward ignores it entirely (`docs/02-domain.md` §8).
- A Mailward-owned role or permission model. There is no third role in v1
  (`docs/policies/authorization.md` §1).
- Login, rate limiting and the password oracle problem
  (`docs/01-architecture.md` §5, `docs/policies/authorization.md` §6).
- 2FA enrolment and panel preferences, which live in Mailward's own database
  (`docs/02-domain.md` §13).
- OpenLDAP administrator representation
  (`docs/decisions/0001-sql-backend-only.md`).

## Open Questions

- **OQ-DA-05** — Is the sentinel literally uppercase `'ALL'`, three bytes, in
  every supported iRedMail version, and is `'ALL'` guaranteed never to be a real
  domain? On MySQL the column's collation would make `'all'` and `'ALL'` compare
  equal; on PostgreSQL it would not
  (`docs/reference/open-questions-research.md`, OQ-02, Hypothesis 2 and the
  verification queries). BR-05's exclusion is only as reliable as this answer.
- **OQ-DA-09** — Confirmation of BR-04 itself. The two-write representation is
  sourced from iRedMail's documentation but **not yet verified against a live
  install**; the procedure in `docs/reference/open-questions-research.md`,
  OQ-02, "How to confirm", must be run. If it is refuted — the installer writes
  no sentinel row — BR-04 and BR-05 both change, the `mailward:promote` contract
  with them, and BR-19 is dropped rather than reworded: with one representation
  there is nothing that can drift.
