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
| Domain admin | Sees the `domain_admins` rows for the domains they administer (`docs/policies/authorization.md` §2). Write authority is undecided — OQ-DA-01 |
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
  Whether `active = 0` revokes a grant is OQ-DA-04.
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
  `docs/decisions/0003-reuse-iredmail-admin-model.md`). Demotion does not by
  itself remove those rows; see OQ-DA-06.

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
| `active` | Written `1`; semantics undecided — OQ-DA-04 |

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
| DELETE | `/admins/{address}` | Demote: subject to BR-A01 and OQ-DA-02 |
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
| `--global` | Promote to global admin. This is the form BR-A04 documents; behaviour without it is OQ-DA-03 |

**Validates, in this order, before any write**

1. The address is syntactically an address and is representable in ASCII
   (BR-07); otherwise it cannot be stored on MySQL.
2. A `mailbox` row exists with that `username`. If not, the command fails
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
non-zero with a single explanatory line when the mailbox does not exist or the
address is invalid.

**Access**: no panel permission gates it. Anyone with shell access to the
server can run it, by design (`docs/policies/authorization.md` §5, BR-A04);
shell access to the mail server is already sufficient to edit `vmail` directly.
It still writes an `audit_log` entry (§7); how the actor is recorded when there
is no logged-in administrator is OQ-DA-07.

## States

An account's administrative state is derived from `vmail`, never stored as an
enum there (`docs/01-architecture.md` §7).

| State | `isadmin` | `isglobaladmin` | `domain_admins` |
|---|---|---|---|
| Not an administrator | 0 | 0 | no rows |
| Domain admin | 1 | 0 | one row per assigned domain |
| Domain admin without domains | 1 | 0 | no rows — logs in, empty state (BR-A03) |
| Global admin | 1 | 1 | a `'ALL'` row (BR-04) |

**Inconsistent** — `isglobaladmin = 1` without the `'ALL'` row, or the `'ALL'`
row without the flag. The two representations are written independently by
iRedMail's tooling and can drift
(`docs/reference/open-questions-research.md`, OQ-02, Hypothesis 3). For the
authorization decision BR-03 settles it: the flag wins. What Mailward should do
about the drift it finds — repair it, report it, or leave it — is OQ-DA-08.

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

## Out of Scope

- Mailbox CRUD itself — creating the account being promoted is the mailboxes
  feature (`docs/00-overview.md` §5).
- Deleting a domain and the cascade of its `domain_admins` rows — that write
  belongs to the domains feature; BR-A03 only requires this feature to render
  the resulting empty state.
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

- **OQ-DA-01** — May a domain admin assign or remove another administrator on a
  domain they administer, or is every write in this feature global-admin only?
  `docs/policies/authorization.md` §2 lists "domain admin assignment" as a
  domain-owned resource and thereby decides **visibility**; it does not decide
  write authority. BR-11 settles only the global flag.
- **OQ-DA-02** — What exactly does "demote" write? Removing the
  `domain_admins` rows while leaving `isadmin = 1` produces the state BR-A03
  describes — logs in, sees an empty state. Clearing `isadmin` instead removes
  panel access entirely (`docs/policies/authorization.md` §6). Both are
  consistent with the policy; nothing in `docs/` chooses between them.
- **OQ-DA-03** — What does `mailward:promote <address>` do without `--global`?
  BR-A04 documents only the `--global` form. Whether the command also grants
  per-domain administration, and with what argument, is not decided.
- **OQ-DA-04** — Does `domain_admins.active = 0` revoke a grant? Nothing sourced
  says any iRedMail component reads that column, or its `expired` date
  (`docs/reference/open-questions-research.md`, "Still unknown", OQ-02). Until
  answered, Mailward writes `active = 1` and does not offer a disable action.
- **OQ-DA-05** — Is the sentinel literally uppercase `'ALL'`, three bytes, in
  every supported iRedMail version, and is `'ALL'` guaranteed never to be a real
  domain? On MySQL the column's collation would make `'all'` and `'ALL'` compare
  equal; on PostgreSQL it would not
  (`docs/reference/open-questions-research.md`, OQ-02, Hypothesis 2 and the
  verification queries). BR-05's exclusion is only as reliable as this answer.
- **OQ-DA-06** — Does demotion remove the account's rows in Mailward's own
  database — `panel_profiles`, `two_factor_secrets`? `docs/02-domain.md` §13
  requires explicit cleanup when a **mailbox** is deleted, and says nothing
  about a demotion, which leaves the mailbox in place.
- **OQ-DA-07** — How is the actor recorded in `audit_log` for a console run of
  `mailward:promote`? `docs/policies/authorization.md` §7 requires an acting
  address, and a shell invocation has no authenticated administrator.
- **OQ-DA-08** — What does Mailward do when it finds the two representations
  drifted — `isglobaladmin = 1` with no `'ALL'` row, or an `'ALL'` row with the
  flag cleared? Repair silently, report it, or ignore it. The drift is
  identified as possible in `docs/reference/open-questions-research.md`, OQ-02,
  Hypothesis 3, and no document decides the response.
- **OQ-DA-09** — Confirmation of BR-04 itself. The two-write representation is
  sourced from iRedMail's documentation but **not yet verified against a live
  install**; the procedure in `docs/reference/open-questions-research.md`,
  OQ-02, "How to confirm", must be run. If it is refuted — the installer writes
  no sentinel row — BR-04 and BR-05 both change, and the `mailward:promote`
  contract with them.
