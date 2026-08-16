# Mailboxes

Status: draft

Sources: `docs/00-overview.md` §5 (v1: *"Mailboxes: full CRUD, quota, password,
enabled services, active/inactive"*, *"Quota usage, per account and per
domain"*, *"Last login per account"*), `docs/02-domain.md` §1, §2, §4, §5, §9,
§10, §11, §13, `docs/01-architecture.md` §2, §3, §6,
`docs/policies/authorization.md`, `docs/reference/schema-type-matrix.md`,
`docs/decisions/0004`, `0005`, `0007`.

---

## Purpose

Administer real mail accounts — rows in `vmail.mailbox` — through the panel:
create, list, edit, deactivate and delete them; set the quota limit, the mail
password and the enabled services; and read back the usage and last-login
figures iRedMail's own components maintain.

This is the feature the rest of the product hangs off: an account created here
is the same account Postfix delivers to, Dovecot authenticates and the panel
logs in with.

## Actors

| Actor | May act on |
|---|---|
| Global admin | Every mailbox in every domain |
| Domain admin | Only mailboxes whose `mailbox.domain` is one of their administered domains |

Mail users have no access in v1 (`docs/policies/authorization.md` §1).

`docs/policies/authorization.md` applies in full and is not restated here. This
feature narrows nothing in it, and adds no role.

## Business Rules

- **BR-01** — A mailbox is a domain-owned resource. Its domain is the
  `mailbox.domain` column, and the scope rule of
  `docs/policies/authorization.md` §2 is enforced in the query, as a default
  scope on the model, not by filtering loaded results (§3 of that policy).
- **BR-02** — Every address accepted by this feature — the mailbox address, and
  any address used to look one up — is trimmed and lowercased at the request
  boundary (`docs/decisions/0005-lowercase-canonical-addresses.md`). Stated once
  here; it applies to every input and every lookup below.
- **BR-03** — Address uniqueness is verified by the application against the
  canonical lowercase form before insert. The database unique index is not
  relied upon: it catches case duplicates on MySQL and misses them on
  PostgreSQL (`0005` Consequences; matrix D17). `mailbox.username` is always
  written explicitly and never left to a column default (matrix D16).
- **BR-04** — **Creating a mailbox is never a single insert.** Every mailbox has
  a row in `forwardings` pointing at itself — `address = forwarding =` the
  account's address, `is_forwarding = 1`. A mailbox created without that row
  appears correct in every listing and receives no mail (`02-domain.md` §5).
  The `mailbox` insert and the `forwardings` insert happen in one transaction on
  the `vmail` connection; if either fails, neither is committed.
- **BR-05** — The `domain.mailboxes` limit is enforced before an account is
  created. `0` means unlimited, not "zero allowed" (`02-domain.md` §2).
- **BR-06** — `created`, `modified`, `passwordlastchange` and `expired` are
  written explicitly on every insert and never left to the column default: the
  "never set" sentinel does not exist on PostgreSQL (`02-domain.md` §1.2, matrix
  D1). The `expired` "never expires" sentinel is written per driver and never
  compared for equality; expiry is evaluated as `expired > now()` (matrix D2).
- **BR-07** — `active`, `isadmin`, `isglobaladmin` and the ~30 integer `enable*`
  toggles are written and compared as `1`/`0`, never as `true`/`false`
  (`02-domain.md` §1.3, matrix D4). `enablesogowebmail`, `enablesogocalendar`
  and `enablesogoactivesync` are character columns holding `'y'`/`'n'`; they
  never receive a boolean cast, which would write `1` and break SOGo (matrix
  D5).
- **BR-08** — `allow_nets` is written as NULL when the account is unrestricted,
  never as an empty string (`02-domain.md` §4).
- **BR-09** — `mailbox.settings` is neither read nor written; it belongs to
  iRedAdmin-Pro (`02-domain.md` §1.4). The other TEXT columns that diverge in
  nullability are normalised to `''` on write, and `''` and NULL are treated as
  the same absent value on read (matrix D6).
- **BR-10** — `mailbox.password` is the account's real mail password. Changing
  it through Mailward changes IMAP, SMTP and webmail access simultaneously, and
  the interface must present it as such (`02-domain.md` §4,
  `01-architecture.md` §5).
- **BR-11** — A password is written using the single *generative* scheme
  selected by instance configuration; every other supported scheme is only
  *verifiable*. Mailward must be able to verify what it generates
  (`docs/decisions/0007-configurable-maildir-and-password-scheme.md`).
- **BR-12** — `maildir`, `storagebasedirectory`, `storagenode`, `mailboxformat`
  and `mailboxfolder` are produced by the maildir generator selected by instance
  configuration, from the same inputs iRedMail uses. No path algorithm is
  hard-coded, and the configuration is validated at save time rather than at
  first use (`0007`).
- **BR-13** — The quota *limit* is `mailbox.quota`. Quota *usage* is read from
  `used_quota`, which Dovecot maintains and Mailward never writes
  (`02-domain.md` §9).
- **BR-14** — Per-domain quota usage is derived by correlating
  `used_quota.username` against `mailbox`, on **both** drivers.
  `used_quota.domain` is never read: it is filled by a trigger on MySQL and by
  nothing on PostgreSQL, so grouping on it produces wrong totals with no error
  (`02-domain.md` §9, matrix D14).
- **BR-15** — `last_login` is read-only. It is looked up with an explicit
  `where('username', …)` and never with `find()`, because its primary key
  differs by driver (matrix D12). Its timestamp columns are 32-bit on MySQL and
  overflow in 2038, so consumers tolerate a wrapped or negative value rather
  than trusting the number (`02-domain.md` §10, matrix D13).
- **BR-16** — Deleting a mailbox removes the `mailbox` row and inserts a row
  into `deleted_mailboxes`. iRedMail's own cron job performs the filesystem
  removal. Mailward runs no shell, requires no root and has no privileged helper
  in this feature (`01-architecture.md` §6, `02-domain.md` §11). These two
  writes are part of the larger cascade specified in BR-23.
- **BR-17** — `deleted_mailboxes` is insert-only. Nothing retrieved from it is
  updated or saved back, and no `updateOrCreate` is used: MySQL declares no
  primary key and no unique index on that table (matrix D10). Where a timestamp
  is needed, `delete_date` is used in preference to `timestamp`, which is
  time-zone-converted on MySQL only (matrix D11).
- **BR-18** — Deleting a mailbox explicitly removes the rows in Mailward's own
  database that reference the address — there are no cross-database foreign keys
  to do it. `audit_log` is the exception: it is exempt from that cleanup and
  deliberately keeps references to accounts that no longer exist
  (`02-domain.md` §13, `01-architecture.md` §3,
  `docs/features/audit-log.md` BR-08). Its entries are never modified and never
  deleted individually; they leave only through the age-based retention prune of
  `docs/features/audit-log.md` BR-21, which knows nothing about this deletion.
- **BR-19** — Deactivating, deleting or demoting a mailbox is additionally
  subject to BR-A01 and BR-A02 of `docs/policies/authorization.md` §5, checked
  inside the same transaction as the change.
- **BR-20** — Every write performed by this feature, and every authorization
  failure against it, is recorded per `docs/policies/authorization.md` §7.
- **BR-21** — No operation opens a transaction spanning both databases and no
  query joins across them. An operation touching both sides is ordered so the
  `vmail` write is the last commit, and is idempotent on retry; listings filter
  and paginate on one side and correlate in PHP (`01-architecture.md` §3).
- **BR-22** — Queries against `forwardings` are anchored on `address` or
  `domain`, never on `is_forwarding` alone: MySQL has no index on that flag
  (matrix D15). `rank` and the hyphenated `enable*` columns are accessed through
  the query builder only, never through raw SQL, because their quoting is
  driver-specific (matrix D19).
- **BR-23** — Deleting a mailbox is an **explicit cascade** inside `vmail`. It
  is neither refused because rows reference the account, nor allowed to leave
  orphans that Postfix would still act on: Mailward deletes them itself, because
  there are no foreign keys (`docs/reference/decisions-needed.md` D1;
  `docs/decisions/0002-separate-application-database.md`). In one transaction on
  the `vmail` connection, deleting address `A` removes:
  1. the `mailbox` row for `A`;
  2. the self-referencing `forwardings` row of BR-04;
  3. every `forwardings` row owned by `A` with `is_alias = 1` or
     `is_forwarding = 1` — the account's per-user aliases and forwardings
     (`docs/features/mailbox-aliases-forwardings.md` BR-14; which column carries
     the owner is OQ-A1 of that document and does not change what is deleted);
  4. every `forwardings` row whose `forwarding` column is `A` — the account as
     somebody else's forwarding target;
  5. every `forwardings` row with `is_list = 1` whose `forwarding` column is
     `A` — the account's membership of any standalone alias account, leaving the
     alias accounts themselves intact (`docs/features/aliases.md` BR-02);
  6. every `domain_admins` row whose `username` is `A` — every domain the
     account administered, and the `'ALL'` sentinel row if it held one (BR-27);
  and inserts exactly one `deleted_mailboxes` row (BR-16, BR-17, BR-24) so
  iRedMail's cron removes the files. Rows carrying `is_maillist = 1` are not
  touched: mailing lists are unmodelled in v1 (`02-domain.md` §12).
- **BR-24** — The `maildir` value written into that `deleted_mailboxes` row is
  the concatenation `storagebasedirectory` + `/` + `storagenode` + `/` +
  `maildir`, read from the `mailbox` row being deleted.
  `deleted_mailboxes.maildir` is documented as an **absolute** path
  (`02-domain.md` §11) while `mailbox.maildir` is the relative tail below the
  other two columns — the shape Dovecot's own `user_query` concatenates
  (`docs/reference/open-questions-research.md` OQ-03, established fact 1;
  `docs/reference/current-iredmail-behaviour.md` Q1, fact 1). Writing
  `mailbox.maildir` alone would make iRedMail's cron delete nothing, or resolve
  a path nobody intended. **This derivation is not yet confirmed against a
  running install**; OQ-M3 holds that one confirmation and no deletion may be
  shipped before it is run.
- **BR-25** — No transaction spans both databases (BR-21,
  `01-architecture.md` §3), so the deletion is ordered: the Mailward-side
  cleanup of BR-18 commits **first**, and the `vmail` transaction of BR-23 is
  the last commit. The operation is **idempotent on retry** — repeating it after
  a partial failure deletes whatever remains, succeeds when nothing remains, and
  never inserts a second `deleted_mailboxes` row for an address whose `mailbox`
  row is already gone. `deleted_mailboxes` cannot enforce that itself: MySQL
  declares no primary key and no unique index on it (BR-17, matrix D10)
  (`docs/reference/decisions-needed.md` D1).
- **BR-26** — **The domain part of `mailbox.username` must already exist as a
  row in `domain`**, checked with an explicit `EXISTS` on the `vmail` connection
  inside the same transaction as the insert, on the already lower-cased value
  (BR-02), and refused as a validation error on the address field. It is the
  same rule `02-domain.md` §3 already imposes on `alias_domain.target_domain`,
  and it catches the typo that otherwise creates an account the server will
  never deliver to. A row in `alias_domain` does **not** satisfy it: an alias
  domain has no accounts of its own and its mail resolves to the target domain's
  accounts (`02-domain.md` §3), so a mailbox created inside one would be
  unreachable. In practice the create form already picks a domain the actor may
  create in (Contracts), so this rule is what makes that list authoritative
  rather than advisory. **Collisions are not refused**: `mailbox.username` may
  equal an existing `alias.address`, and no cross-table uniqueness check against
  `alias`, `forwardings` or `alias_domain` is performed — BR-03's uniqueness is
  within `mailbox` alone. Which of the two wins at delivery is a property of the
  mail server, and the probe that observes it
  (`docs/reference/decisions-needed.md` E7) is informational rather than
  blocking (`docs/reference/decisions-needed.md` Q4, answered 2026-08-15,
  option C).
- **BR-27** — **Deleting a mailbox also removes that account's `domain_admins`
  rows**, in the same `vmail` transaction as the rest of BR-23 (item 6). This is
  a security rule, not tidiness: `domain_admins` is keyed by address and nothing
  else, so leaving the rows behind means that deleting `admin@example.com` and
  then re-creating the same address silently regains every domain the old
  account administered — a privilege escalation performed by an administrator
  who believed they were creating a new, unprivileged account. Removing them
  makes the mailbox cascade symmetric with the domain cascade, which already
  removes the rows keyed by `domain` (`docs/policies/authorization.md` BR-A03,
  `docs/features/domains.md` BR-13). The deletion is still subject to BR-A01
  (BR-19): the last global admin cannot be deleted, so this rule can never be
  the step that empties the panel of administrators
  (`docs/reference/decisions-needed.md` Q6, answered 2026-08-15).

- **BR-28** — **`domain.maxquota` is an aggregate pool, not a per-mailbox
  ceiling**, and both it and `mailbox.quota` are in **mebibytes**. Before a
  mailbox is created or its quota raised, Mailward sums the quotas already
  allocated in the domain and compares the request against what remains. A
  `maxquota` of `0` means unlimited and skips the check entirely. This is
  iRedMail's own semantics, read from its account-creation path; counting
  differently would make the two panels disagree about whether a domain is full
  (`docs/reference/decisions-needed.md` Q15, corrected 2026-08-15 against
  `docs/reference/observed-install.md`).

  **iRedMail truncates where Mailward refuses.** Its own code silently reduces
  the requested quota to whatever is left — `mailQuota = spareQuota` — and only
  errors when the balance is zero or negative. Mailward refuses instead, with a
  message naming the remaining balance. Silently granting an administrator a
  different quota than the one they typed is the same class of defect this
  document guards against everywhere else: it looks like it worked. The
  divergence is deliberate and is the one place Mailward behaves differently
  from iRedAdmin on the same action.

  **Unexercised on the first deployment.** Every domain there has
  `maxquota = 0`, so no legacy data validates this path — it is written from
  iRedMail's code, not from observed rows.
- **BR-29** — **The `domain.mailboxes` limit of BR-05 is counted inside the
  create transaction, without locking, and the count-then-insert race is
  accepted.** The count runs on the `vmail` connection inside the same
  transaction as the insert — never before the transaction opens — and the
  `domain` row is **not** locked for update, so creates within one domain are not
  serialised. The consequence is recorded here as a property of the product
  rather than left to be reported later as a defect: **a per-domain limit is a
  guardrail, not a guarantee.** Two creates racing in the same domain can both
  pass the check and leave the domain one account over its limit; the next create
  counts the true total, finds it at or above the limit, and is refused. The
  excess is therefore bounded by one per race and never compounds. This is
  deliberate: nothing in the product bills on these limits, `0` already means
  unlimited (BR-05), and `docs/policies/authorization.md` §5 requires a
  same-transaction check only for the last-global-admin case — a lockout rule,
  where being wrong by one is being wrong absolutely, which a count limit is not.
  Two visible consequences, both already true of the specification and neither a
  defect: a dashboard or listing may show a count **greater** than its limit, and
  "at limit" is therefore defined as `count >= limit` rather than `count = limit`
  (`docs/features/domains.md` BR-04, `docs/features/dashboard.md` BR-08). The
  same treatment applies to `domain.aliases` (`docs/features/aliases.md` BR-06)
  for the same reason (`docs/reference/decisions-needed.md` Q16, answered
  2026-08-15, option A).
- **BR-30** — **The form exposes a curated subset of the `enable*` toggles and
  writes no column outside it.** The subset is six toggles, each of which moves
  its whole family of plain and secured/TLS variants together:
  1. **SMTP** — `enablesmtp`, `enablesmtpsecured`;
  2. **POP3** — `enablepop3`, `enablepop3secured`, `enablepop3tls`;
  3. **IMAP** — `enableimap`, `enableimapsecured`, `enableimaptls`;
  4. **Delivery (LDA/LMTP)** — `enabledeliver`, `enablelda`, `enablelmtp`;
  5. **Sieve / ManageSieve** — `enablesieve`, `enablesievesecured`,
     `enablesievetls`, `enablemanagesieve`, `enablemanagesievesecured`;
  6. **SOGo** — `enablesogo`, plus the three character columns below.
  Every other `enable*` column — `enableinternal`, `enabledoveadm`,
  `enabledsync`, and the Dovecot internals `enablelib-storage`,
  `enablequota-status` and `enableindexer-worker` — **keeps whatever iRedMail
  set**, is never rendered, never validated and **never named in an INSERT or
  UPDATE column list**, so those columns are byte-identical after any Mailward
  write. On create they are left to their schema defaults, which is what
  iRedMail's own tooling produces. The three Dovecot internals are **deliberately
  unreachable**, not merely hidden: they are service plumbing no administrator
  has a reason to switch off, switching one off breaks the account in a way the
  panel cannot explain, and they are among the columns whose hyphenated
  identifiers quote differently per driver (matrix D19, BR-22).
  **`enablesogo` gates the three SOGo character columns**: writing
  `enablesogo = 1` writes `'y'` to `enablesogowebmail`, `enablesogocalendar` and
  `enablesogoactivesync`; writing `enablesogo = 0` writes `'n'` to all three.
  They move only with it and are not independently exposed in v1. Those three are
  character columns and never receive a boolean cast, which would write `1` and
  break SOGo (BR-07, matrix D5); the integer toggles are written `1`/`0` (BR-07).
  The reason for a subset rather than all ~30 is legibility: a form of thirty
  checkboxes is a form nobody reads, and the scope line asks for "enabled
  services", not for every column whose name begins with `enable`
  (`docs/reference/decisions-needed.md` Q20, answered 2026-08-15, option A).

## Data

**Written** — `vmail.mailbox`, primary key `username` (the full address):

| Group | Columns |
|---|---|
| Identity | `username`, `password`, `name`, `first_name`, `last_name`, `language` |
| Profile | `mobile`, `telephone`, `recovery_email`, `birthday`, `department`, `rank`, `employeeid` |
| Storage | `mailboxformat`, `mailboxfolder`, `storagebasedirectory`, `storagenode`, `maildir`, `quota`, `domain`, `transport` |
| Services | the curated `enable*` subset of BR-30 only, as `1`/`0`, plus `enablesogowebmail` / `enablesogocalendar` / `enablesogoactivesync` as `'y'`/`'n'` characters gated by `enablesogo`. The remaining `enable*` columns are read-only context and appear in no INSERT or UPDATE (BR-30) |
| Access | `allow_nets` (NULL when unrestricted) |
| Dates | `passwordlastchange`, `created`, `modified`, `expired` |
| State | `active` |

`password` is `VARCHAR(255)`. `isadmin` / `isglobaladmin` are read here and
written by the domain-admins feature, not by this one. `settings` and
`disclaimer` are not surfaced (BR-09, Out of Scope).

**Also written** — `vmail.forwardings`: on create, the self-referencing row of
BR-04 only; on delete, every row enumerated in BR-23. Outside those two paths,
rows in that table belong to `docs/features/mailbox-aliases-forwardings.md` or
to the standalone alias feature.

**Also written, on delete only** — `vmail.domain_admins`: the rows keyed by the
deleted address (BR-23 item 6, BR-27). Every other write to that table belongs
to `docs/features/domain-admins.md`.

**Insert-only** — `vmail.deleted_mailboxes`: `username`, `domain`, `maildir`,
`bytes`, `messages`, `admin` (the acting address), `delete_date`.

**Read-only** — `vmail.used_quota` (`username`, `bytes`, `messages`;
`domain` never read), `vmail.last_login` (`username`, `imap`, `pop3`, `lda`),
`vmail.domain` (`domain` for the locality check of BR-26, `mailboxes` for the
limit of BR-05 and BR-29, `maxquota` for the per-mailbox cap of BR-28, plus
`active`, `expired`).

**Mailward's own database** — `panel_profiles`, `two_factor_secrets` and any
other table keyed by the address are cleaned on delete; `audit_log` is written
and never cleaned (BR-18).

## Contracts

Inertia pages and form endpoints only. No JSON API is exposed for the panel's
own use (`docs/decisions/0004-inertia-vue.md`). `{mailbox}` is the URL-encoded
canonical address. Validation lives in FormRequests; failures return the
validation-error redirect, not JSON.

| Method | Path | Page / result |
|---|---|---|
| GET | `/mailboxes` | `Mailboxes/Index` — paginated, scoped (BR-01); filters: domain, active, search; per-account usage (BR-13) |
| GET | `/mailboxes/create` | `Mailboxes/Create` — domains the actor may create in, remaining `domain.mailboxes` allowance |
| POST | `/mailboxes` | Creates `mailbox` + self-forwarding in one transaction (BR-04); redirects to the account |
| GET | `/mailboxes/{mailbox}` | `Mailboxes/Show` — quota limit and usage, `last_login` values, service toggles |
| GET | `/mailboxes/{mailbox}/edit` | `Mailboxes/Edit` |
| PUT | `/mailboxes/{mailbox}` | Updates profile, quota, services, `allow_nets`, `active` |
| PUT | `/mailboxes/{mailbox}/password` | Changes the real mail password (BR-10, BR-11) |
| PATCH | `/mailboxes/{mailbox}/active` | Activate / deactivate (BR-07, BR-19) |
| DELETE | `/mailboxes/{mailbox}` | Deletes per BR-16, BR-17, BR-18 and the cascade of BR-23, BR-24, BR-25 |

Authorization props are passed to the frontend for showing and hiding controls
only; every request is authorized again on the server
(`docs/policies/authorization.md` §4).

Error cases: 403 for a resource outside the actor's scope (logged per BR-20);
422 with field errors for validation, including the uniqueness check of BR-03,
the locality check of BR-26, the limit of BR-05 and the quota cap of BR-28;
409-equivalent (validation error) when BR-A01 would be violated.

## States

```
(none) --create--> active --deactivate--> inactive --activate--> active
                     |                        |
                     +--------delete----------+
                                 |
                                 v
                              deleted   (terminal)
```

- A mailbox is created `active = 1` with the per-driver "never expires"
  sentinel in `expired` (BR-06).
- `inactive` is `active = 0`. An account that is inactive, or whose `expired`
  date is in the past, is denied at both authentication and authorization steps
  (`docs/policies/authorization.md` §6).
- `deleted` is terminal: the `mailbox` row is gone and a `deleted_mailboxes` row
  exists. There is no undelete — that row is never updated (BR-17). Re-creating
  the same address is a new create and must satisfy BR-04 again.
- Forbidden transitions: `active → inactive` and `* → deleted` for the last
  global admin (BR-19).
- Setting an expiry date is not a transition this feature offers (Out of Scope).

## Acceptance Criteria

- **AC-01** — Given a valid create request, when the mailbox is created, then a
  `vmail.mailbox` row exists **and** a `vmail.forwardings` row exists with
  `address = forwarding =` the account's address and `is_forwarding = 1`.
- **AC-02** — Given the `forwardings` insert fails, when the create is
  attempted, then no `mailbox` row remains: both writes are rolled back.
- **AC-03** — Given an address entered as `User@Example.COM`, when it is
  created, then `mailbox.username`, `mailbox.domain` and both `forwardings`
  address columns hold the lowercase form.
- **AC-04** — Given `user@example.com` exists, when `USER@example.com` is
  submitted, then the request fails validation with a duplicate-address error,
  on MySQL **and** on PostgreSQL.
- **AC-05** — Given a domain admin administering `a.com` only, when they open
  `/mailboxes` on a fixture containing mailboxes in `a.com` and `b.com`, then
  only `a.com` rows are returned, on every page, and the executed query itself
  is constrained by domain.
- **AC-06** — Given a domain admin, when they GET, PUT, PATCH or DELETE a
  mailbox in a domain they do not administer, then the response is 403 and an
  authorization failure is recorded.
- **AC-07** — Given `domain.mailboxes = 2` and two accounts existing, when a
  third is created, then the request fails validation; given
  `domain.mailboxes = 0`, when an account is created, then it succeeds.
- **AC-08** — Given a create request at a frozen clock, when the row is
  inserted, then `created`, `modified` and `passwordlastchange` equal the
  application-supplied value on both drivers — never `1970-01-01 01:01:01` and
  never the PostgreSQL `NOW()` default — and `expired` equals that driver's
  "never expires" sentinel.
- **AC-09** — Given the SOGo toggles are enabled, when the row is written, then
  `enablesogowebmail`, `enablesogocalendar` and `enablesogoactivesync` hold the
  literal `'y'`, and when disabled they hold `'n'`. No value of `1` or `0` is
  ever written to them.
- **AC-10** — Given a listing filtered by `active`, when it runs on PostgreSQL,
  then the query executes without a type error and returns the expected rows
  (the filter binds `1`/`0`, not `true`/`false`).
- **AC-11** — Given the access-restriction field is submitted empty, when the
  row is written, then `allow_nets` is SQL NULL, not `''`.
- **AC-12** — Given a mailbox is created or updated, when the write completes,
  then `mailbox.settings` is unchanged and appears in no SELECT column list.
- **AC-13** — Given a password change, when it is submitted, then
  `mailbox.password` holds a hash in the configured generative scheme, and
  Mailward's own verifier accepts the submitted password against the stored
  value.
- **AC-14** — Given the edit and password screens, when they render, then the
  interface states that the password is the account's mail password and that
  changing it changes IMAP, SMTP and webmail access.
- **AC-15** — Given the configured maildir generator, when a mailbox is created,
  then `maildir`, `storagebasedirectory` and `storagenode` equal exactly what
  that generator produces for the address, and no path constant appears in the
  create path.
- **AC-16** — Given a mailbox is deleted, when the operation completes, then the
  `mailbox` row is gone, exactly one `deleted_mailboxes` row was inserted
  carrying `username`, `domain`, `maildir`, `admin` (the acting address) and
  `delete_date`, and no UPDATE was issued against `deleted_mailboxes`.
- **AC-17** — Given a mailbox with rows in `panel_profiles` and
  `two_factor_secrets` and entries in `audit_log`, when it is deleted, then the
  first two are removed and the `audit_log` entries remain, still referencing
  the address.
- **AC-18** — Given exactly one global admin, when a request attempts to
  deactivate, delete or demote it, then the change is rejected and the row is
  unchanged.
- **AC-19** — Given any mailbox create, update, password change, activation
  change or delete, when it succeeds, then an `audit_log` entry exists with the
  acting address, the action, the target address and before/after values.
- **AC-20** — Given any operation in this feature, when it runs, then no
  INSERT, UPDATE or DELETE is issued against `used_quota` or `last_login`.
- **AC-21** — Given the same fixture loaded on MySQL and on PostgreSQL, when
  per-domain quota usage is computed, then both drivers return identical totals,
  and no executed query references `used_quota.domain`.
- **AC-22** — Given a mailbox with no `used_quota` row, when the listing runs,
  then the mailbox still appears in it.
- **AC-23** — Given a `last_login` row, when the account page renders, then the
  lookup used `where('username', …)` rather than `find()`, and a negative or
  wrapped timestamp value renders without an exception.
- **AC-24** — Given a mailbox whose `expired` is in the past, when expiry is
  evaluated, then it is treated as expired on both drivers, with no comparison
  against a hard-coded sentinel string.
- **AC-25** — Given `user@a.com` with its self-referencing row, two `is_alias`
  rows, one `is_forwarding` row, one row belonging to `other@a.com` whose
  `forwarding` is `user@a.com`, one `is_list` membership of `sales@a.com` and
  one row carrying `is_maillist = 1` that names it, when the mailbox is deleted,
  then the first five are gone, the `alias` row `sales@a.com` still exists, and
  the `is_maillist` row is unchanged (BR-23).
- **AC-26** — Given a mailbox whose `storagebasedirectory` is `/var/vmail`,
  `storagenode` is `vmail1` and `maildir` is
  `a.com/u/us/use/user-2026.08.15.10.00.00/`, when it is deleted, then
  `deleted_mailboxes.maildir` holds
  `/var/vmail/vmail1/a.com/u/us/use/user-2026.08.15.10.00.00/` — the three
  columns concatenated, never `mailbox.maildir` on its own (BR-24).
- **AC-27** — Given the `vmail` transaction of a mailbox deletion fails after
  the Mailward-side rows of BR-18 were committed, when the deletion is retried,
  then it succeeds, every row named in BR-23 is gone, and exactly one
  `deleted_mailboxes` row exists for that address — never two (BR-25).
- **AC-28** — Given no `domain` row for `nope.test`, when a create is submitted
  for `user@nope.test`, then it fails validation on the address and neither a
  `mailbox` row nor a `forwardings` row is written; and given `alias.test`
  exists only as an `alias_domain` row, when `user@alias.test` is submitted,
  then it fails validation the same way — an alias domain does not satisfy
  locality (BR-26).
- **AC-29** — Given a standalone `alias` row `sales@a.com`, when a mailbox
  `sales@a.com` is created, then the create succeeds, both rows coexist, and no
  statement executed by the create queried `alias`, `forwardings` or
  `alias_domain` for a conflicting name (BR-26, BR-03).
- **AC-30** — Given `admin@a.com` with `isadmin = 1`, two per-domain
  `domain_admins` rows and a second global admin existing, when the mailbox is
  deleted, then no `domain_admins` row naming `admin@a.com` remains; and when a
  new mailbox is created at the same address, then it holds no `domain_admins`
  row, `isadmin = 0` and `isglobaladmin = 0`, and it administers nothing
  (BR-27).
- **AC-31** — Given the sole global admin, when its deletion is attempted, then
  the request is refused by BR-A01, the `mailbox` row is unchanged, and its
  `domain_admins` rows — including the `'ALL'` row — are all still present: the
  cascade of BR-27 never runs on a refused delete (BR-19, BR-27).
- **AC-32** — Given a domain whose `maxquota` is `1000`, when a mailbox is
  created with `quota = 1000`, then it succeeds; when one is created with
  `quota = 1001`, then it fails validation on the quota field and neither a
  `mailbox` row nor a `forwardings` row is written; and when an existing mailbox
  in that domain is updated to `quota = 1001`, then it fails the same way and the
  stored `quota` is unchanged. *(BR-28)*
- **AC-33** — Given a domain whose `maxquota` is `1000`, when a mailbox is
  created with `quota = 0`, then it fails validation — unlimited exceeds the
  cap; and given a domain whose `maxquota` is `0`, when a mailbox is created with
  any `quota`, including `0`, then it succeeds and no comparison against
  `maxquota` appears in the executed statements. *(BR-28)*
- **AC-34** — Given a domain whose `maxquota` is `1000` and two mailboxes already
  holding `quota = 1000`, when the domain's `maxquota` is lowered to `500`, then
  the update succeeds, both mailboxes still hold `1000`, no UPDATE was issued
  against `mailbox`, and the next edit of either mailbox that submits
  `quota = 1000` fails validation. *(BR-28)*
- **AC-35** — Given a domain with `mailboxes = 5` and four existing accounts,
  when two creates run concurrently, then both may succeed and the domain may
  hold six accounts; when a further create is attempted afterwards, then it is
  refused by BR-05; and when the create statements are inspected, then the count
  was issued inside the same transaction as the insert and no `SELECT … FOR
  UPDATE` or other lock was taken on the `domain` row. *(BR-29, BR-05)*
- **AC-36** — Given that same domain holding six accounts against a limit of
  five, when a listing or the dashboard renders it, then it reports `count = 6`,
  `limit = 5` and `at_limit = true`, and neither screen raises an error on a
  count that exceeds its limit. *(BR-29, `docs/features/domains.md` BR-04)*
- **AC-37** — Given a mailbox whose `enableinternal`, `enabledoveadm`,
  `enabledsync`, `enablelib-storage`, `enablequota-status` and
  `enableindexer-worker` hold arbitrary pre-existing values, when it is updated
  through every field the form offers, then all six columns are byte-for-byte
  unchanged, none appears in any executed INSERT or UPDATE column list, and none
  appears in any Inertia prop or form control. *(BR-30)*
- **AC-38** — Given the mailbox form, when it renders, then it offers exactly six
  service toggles — SMTP, POP3, IMAP, delivery, Sieve/ManageSieve and SOGo — and
  when SMTP is turned off, then `enablesmtp` and `enablesmtpsecured` are both
  `0`; when POP3 is turned off, then `enablepop3`, `enablepop3secured` and
  `enablepop3tls` are all `0`; and the equivalent holds for IMAP, delivery and
  Sieve/ManageSieve across every column named in BR-30. *(BR-30)*
- **AC-39** — Given SOGo is turned off, when the row is written, then
  `enablesogo` is the integer `0` and `enablesogowebmail`, `enablesogocalendar`
  and `enablesogoactivesync` all hold `'n'`; when it is turned on, then
  `enablesogo` is `1` and all three hold `'y'`; and the form offers no
  independent control for any of the three. *(BR-30, BR-07, AC-09)*

## Out of Scope

- Self-service by mail users — own password, own vacation, own forwardings
  (`00-overview.md` §5, Later).
- Per-mailbox `disclaimer`: it is applied by Amavis + AlterMIME, and Amavis
  features are not in v1 (`00-overview.md` §5, `01-architecture.md` §3).
- `mailbox.settings` in any form (BR-09).
- Setting or editing `expired`; only the "never expires" sentinel is written
  (BR-06). Account expiry management is not in the v1 scope line.
- Promoting or demoting administrators, and assigning domains — the domain
  admins feature.
- Per-user aliases and forwardings beyond the self-referencing row of BR-04 —
  `docs/features/mailbox-aliases-forwardings.md`.
- Standalone alias accounts (`vmail.alias`, `forwardings.is_list`) and mailing
  lists (`forwardings.is_maillist`).
- Domain-level and instance-level aggregates — account counts, quota totals,
  domains at their limit, dormant-account reporting — which belong to the
  dashboard feature. This document defines only how per-domain usage must be
  *computed* (BR-14).
- Restoring a deleted mailbox, and any interaction with iRedMail's removal cron
  beyond inserting the row (BR-16).
- Bulk import, bulk edit and CSV export.

## Open Questions

- **OQ-M10** — What happens when a domain is **both** a `domain` row and an
  `alias_domain` row? BR-26 refuses a mailbox whose domain exists only in
  `alias_domain`, and `alias-domains.md` BR-13 deliberately permits a name to be
  both. Together they admit a mailbox that BR-26 was written to prevent: Postfix
  rewrites the alias domain to its target before the mailbox lookup, so a
  mailbox in the overlapping domain is shadowed by the rewrite and never
  receives mail. Neither rule is wrong alone.

  Three ways out, none of them obviously right. Refuse mailbox creation in a
  domain that is also an alias domain — safe, and it makes BR-13's permission
  narrower than it reads. Warn and allow — honest about iRedMail's own tolerance
  and leaves a live footgun. Or leave it and rely on the health check to report
  the overlap, which is where the other silent-dead-account findings already go
  (`docs/00-overview.md` OQ-06).

  **Unobserved.** The precedence is a property of the mail server's map order,
  not of the schema, and no probe has established it. E7 already covers the
  address-level collision; this is the domain-level one.


- **OQ-M2** — Does Dovecot auto-create the maildir on first delivery? If it does
  not, the directory must exist before delivery, and Mailward — running
  unprivileged — cannot create it, which turns mailbox creation into a
  privileged-helper problem and contradicts `01-architecture.md` §6. Listed as
  the single most consequential unknown in
  `docs/reference/open-questions-research.md` (OQ-03, Still unknown).
- **OQ-M3** — What value is written into `deleted_mailboxes.maildir`? That
  column is documented as an absolute path, while `mailbox.maildir` may be the
  relative tail below `storagebasedirectory`/`storagenode`
  (`docs/reference/open-questions-research.md`, OQ-03, established fact 1 and
  its contradiction note against `02-domain.md` §4). If it is relative, the
  three columns must be concatenated when the deletion row is written; get it
  wrong and iRedMail's cron deletes nothing, or resolves a path we did not
  intend.
- **OQ-M5** — Must `passwordlastchange` be updated on every password write, and
  does any iRedMail component enforce expiry from it? Recorded as unsourced in
  `docs/reference/open-questions-research.md` (OQ-04).
