# Domain Model

Status: draft

Mapped from `samples/iredmail/iredmail.mysql` in the iRedMail repository
(read at `master`, 2026-08-14). The PostgreSQL file in the same directory
differs in types and must be cross-checked before models are written.

**Mailward does not own this schema.** Nothing here may be altered. This
document describes what exists, so the application can work with it correctly.

---

## 1. Cross-Cutting Facts

These apply to nearly every table and are the source of most surprises.

### 1.1 Addresses are lowercase, by convention only

iRedMail stores every address in lower case. Nothing in the schema enforces it.

- Postfix folds lookup keys to lower case, so **delivery** tolerates mixed case.
- iRedMail's shipped Dovecot config does not set `auth_username_format`, but
  the setting's **default lowercases** — `%Lu` in Dovecot 2.3,
  `%{user | lower}` in 2.4 — so **authentication always looks up a lower case
  address**, whatever the client typed. On the 2.4 path iRedMail lowercases a
  second time inside the SQL query.
- MySQL's `utf8mb4_general_ci` collation then makes lookups case-insensitive by
  accident; PostgreSQL does not.

Net effect: a mixed-case row works on MySQL and is **unreachable** on
PostgreSQL. Not merely awkward to log into — the lookup key is always lower
case, so the row can never be matched, and the userdb lookup fails alongside
the passdb one. The account has no home and receives no mail. It is a dead
account.

Mailward canonicalises every address to lower case at the request boundary —
`docs/decisions/0005-lowercase-canonical-addresses.md`, whose Context carries a
correction on this exact point. Sourced in
`docs/reference/current-iredmail-behaviour.md` §Q3.

### 1.2 Sentinel dates, not NULL

Date columns are `NOT NULL` with sentinel defaults — **and the sentinels are
not the same on both drivers**:

| Column | MySQL default | PostgreSQL default | Means |
|---|---|---|---|
| `created`, `modified`, `passwordlastchange` | `1970-01-01 01:01:01` | `NOW()` | never set / creation time |
| `expired` | `9999-12-31 00:00:00` | `9999-12-31 01:01:01` | never expires |
| `birthday` | `0001-01-01` | `0001-01-01` | not set |

Two consequences, both of which produce a defect on exactly one driver:

- The "never set" sentinel **does not exist on PostgreSQL**, where the column
  defaults to the current time. Nothing may infer "never changed" from the
  sentinel. Mailward writes these columns explicitly on every insert and never
  lets the column default fire.
- `expired` must be tested as `expired > now()`, never compared for equality
  against a hard-coded sentinel, and the sentinel is written per driver.

`0001-01-01` is also rejected by MySQL in strict mode — verify the server's
`sql_mode`.

Full comparison: `docs/reference/schema-type-matrix.md` (D1, D2, D3).

### 1.3 Booleans are `TINYINT(1)`, except when they are not

Neither driver uses a native boolean. Most flags are `TINYINT(1)` on MySQL and
`INT2` on PostgreSQL, which means query builder comparisons must bind `1`/`0`
and never `true`/`false` — PostgreSQL has no implicit cast from `smallint` to
`boolean` and errors on `active = true`, where MySQL silently accepts it
(`docs/reference/schema-type-matrix.md`, D4).

Three flags are a character type in both, holding `'y'`/`'n'`:

```sql
enablesogowebmail    CHAR(1) NOT NULL DEFAULT 'y'
enablesogocalendar   CHAR(1) NOT NULL DEFAULT 'y'
enablesogoactivesync CHAR(1) NOT NULL DEFAULT 'y'
```

The schema comment says the character type is required, not an int. Casting
these as booleans without a custom cast writes `1` and breaks SOGo.

### 1.4 `settings TEXT` belongs to iRedAdmin-Pro

`admin.settings`, `domain.settings`, `mailbox.settings` and `maillists.settings`
are annotated in the schema as "Used in iRedAdmin-Pro". Mailward neither reads
nor writes them. Its own configuration lives in its own database.

### 1.5 No timestamps in Laravel's shape

Columns are `created` / `modified`, not `created_at` / `updated_at`. All mail
models set `$timestamps = false` and manage those columns explicitly.

---

## 2. Domain

Table `domain`, primary key `domain` (the domain name).

| Column | Meaning |
|---|---|
| `domain` | Domain name, lower case |
| `description` | Free text |
| `disclaimer` | Appended by Amavis + AlterMIME |
| `aliases` | Max alias accounts; `0` means unlimited |
| `mailboxes` | Max mail accounts |
| `maillists` | Max mailing lists |
| `maxquota` | Max quota for the domain, bytes |
| `quota` | Historical, unused — do not surface it |
| `transport` | Per-domain transport, default `dovecot` |
| `backupmx` | Domain is a backup MX only |
| `settings` | iRedAdmin-Pro — ignored |
| `created`, `modified`, `expired`, `active` | See 1.2 |

**BR:** `aliases`, `mailboxes` and `maillists` are limits Mailward must enforce
before creating an account, and must surface on the dashboard when reached.
`0` means unlimited — not "zero allowed".

## 3. Alias Domain

Table `alias_domain`, primary key `alias_domain`.

Maps `alias_domain` → `target_domain`. Mail addressed to the alias domain is
delivered to the accounts of the target domain.

**BR:** the target domain must exist in `domain`. Nothing in the schema
enforces this.

## 4. Mailbox

Table `mailbox`, primary key `username` (the full email address).

The largest table in the schema. Grouped by purpose:

**Identity and profile**
`username`, `password`, `name`, `first_name`, `last_name`, `language`,
`mobile`, `telephone`, `recovery_email`, `birthday`, `department`, `rank`,
`employeeid`

**Storage**
`mailboxformat` (default `maildir`), `mailboxfolder` (default `Maildir`),
`storagebasedirectory`, `storagenode`, `maildir`, `quota`, `domain`,
`transport`

**`maildir` is a relative tail, not an absolute path.** Dovecot's shipped
`user_query` builds the real location by concatenating `storagebasedirectory`,
`storagenode` and `maildir`. Only `deleted_mailboxes.maildir` is absolute,
which is where the confusion originates — so the deletion record stores the
concatenation, not this column's value
(`docs/reference/current-iredmail-behaviour.md`).

The unit of `quota` is **not settled**: this document has said bytes, and
iRedMail's own Dovecot query multiplies the column by 1048576, which implies
mebibytes. The two readings differ by a factor of a million and both look
plausible on screen. See OQ-05 in `docs/00-overview.md`; no quota value is
written until it is resolved.

**Administration**
`isadmin`, `isglobaladmin`

**Service toggles** — roughly thirty `enable*` columns covering SMTP, POP3,
IMAP, LDA, LMTP, Sieve, ManageSieve, doveadm, dsync, internal, indexer-worker,
lib-storage, quota-status, plus the three SOGo `CHAR(1)` columns from 1.3.

**Other**
`allow_nets` (TEXT, must be NULL when unrestricted — not empty string),
`disclaimer`, `settings`, `passwordlastchange`, `created`, `modified`,
`expired`, `active`

**BR:** `password` is the real mail password. Changing it through Mailward
changes IMAP, SMTP and webmail access simultaneously, and must be presented as
such in the UI.

**OQ-03 is largely dissolved.** The path is instance configuration rather than
a constant (`docs/decisions/0007-configurable-maildir-and-password-scheme.md`),
and the branch that would have hurt is closed: **Dovecot creates the mail
directory itself**, because iRedMail always sets the location explicitly from
SQL. Creating a mailbox is therefore plain SQL from an unprivileged process,
which confirms `01-architecture.md` §6 rather than contradicting it.

What remains is confirming the exact hashing against a running install, and it
is no longer blocking: nothing parses this column, so any path the configured
generator produces is as good as the one iRedMail would have written, provided
it is unique and lower case.

## 5. Forwardings — the important one

Table `forwardings`, surrogate `id`, unique on `(address, forwarding)`.

This single table serves **four** unrelated purposes, discriminated by flags:

| Flag | Meaning |
|---|---|
| `is_forwarding` | A mail user's forwarding address |
| `is_alias` | A per-account alias address |
| `is_list` | Membership of a standalone alias account |
| `is_maillist` | Membership of an mlmmj mailing list |

Columns: `address`, `forwarding`, `domain`, `dest_domain`, plus the flags and
`active`.

**BR — the invariant that breaks silently:** every mailbox has a row here
pointing at itself, with `address = forwarding = the account's address` and
`is_forwarding = 1`. iRedMail's own account-creation script writes it. A
mailbox created without this row appears correct in every listing and does not
receive mail.

Creating a mailbox is therefore never a single insert. It is at minimum
`mailbox` + `forwardings`, in one transaction.

**BR:** the domain model must expose these four concepts as four distinct
entities, not as one "forwardings" screen. The table is shared; the concepts
are not.

## 6. Alias

Table `alias`, primary key `address`.

A standalone alias account: an address that only redirects. Its members are not
here — they are rows in `forwardings` with `is_list = 1`.

Columns: `address`, `name`, `accesspolicy`, `domain`, `created`, `modified`,
`expired`, `active`.

## 7. Domain Admins

Table `domain_admins`, primary key `(username, domain)`.

Grants `username` administrative access to `domain`.

Note `username` and `domain` are `CHARACTER SET ascii` here, unlike elsewhere.

**OQ-02:** how a global admin appears in this table. `mailbox.isglobaladmin` is
the authoritative flag; whether a companion row exists here must be confirmed
against a real install.

## 8. Admin — legacy, ignored

Table `admin` exists in the current schema, with `username`, `password`,
`language`, `settings` and the usual date columns.

It is **not** how administrators are defined today. iRedMail's account creation
script writes administrators into `mailbox` with `isadmin` / `isglobaladmin`
and into `domain_admins`, and does not touch `admin`.

Mailward ignores this table entirely. It is documented here only so nobody
rediscovers it and wires it up by mistake.

## 9. Used Quota — read only

Table `used_quota`, primary key `username`. Columns `bytes`, `messages`,
`domain`.

The schema comment is explicit: *"Don't touch this table, it will be updated by
Dovecot automatically."*

**BR:** Mailward reads this and never writes it. Quota *usage* comes from here;
the quota *limit* is `mailbox.quota`.

**BR — `domain` is only populated on MySQL.** A `BEFORE INSERT` trigger fills it
from the address; the PostgreSQL schema defines no trigger and nothing else
fills the column. Grouping quota usage by `used_quota.domain` therefore produces
correct totals on MySQL and an empty-string bucket on PostgreSQL — wrong numbers,
no error, on a dashboard figure an administrator would trust. Per-domain usage is
correlated through `used_quota.username` against `mailbox` on **both** drivers,
and `used_quota.domain` is never read (`docs/reference/schema-type-matrix.md`,
D14).

## 10. Last Login

Table `last_login`. Columns `username`, `domain`, and `imap`, `pop3`, `lda` as
integer Unix timestamps, nullable.

Useful for the dashboard and for finding dormant accounts. Read only.

**BR — the primary key differs by driver:** `(username)` on MySQL,
`(username, domain)` on PostgreSQL. Eloquent cannot model a composite key, so
this model declares no usable key, sets `$incrementing = false`, and is looked
up with an explicit `where('username', …)` — never `find()`
(`docs/reference/schema-type-matrix.md`, D12).

The timestamp columns are 32-bit `INT` on MySQL, so they overflow in 2038. A
dormant-account report must tolerate a wrapped or negative value rather than
trusting the number (D13).

## 11. Deleted Mailboxes

Table `deleted_mailboxes`. Records `username`, `domain`, `maildir`, `bytes`,
`messages`, the `admin` who deleted it, `delete_date`, and a `timestamp`.

The schema notes that an external cron job performs the actual filesystem
removal.

**BR — insert only, never update.** PostgreSQL gives `id` a `SERIAL PRIMARY
KEY`; MySQL declares no primary key and no unique index at all, so nothing
guarantees `id` is unique there. `find()`, `save()` on a retrieved row and
`updateOrCreate` are all unsafe on MySQL. Mailward writes rows into this table
and never updates them by key (`docs/reference/schema-type-matrix.md`, D10).

`timestamp` is MySQL's time-zone-converting `TIMESTAMP` type and PostgreSQL's
naive `TIMESTAMP WITHOUT TIME ZONE`, so the same row can read differently
through the two drivers. Prefer `delete_date` (D11).

**This is how Mailward deletes mail storage.** It writes the row; iRedMail's
existing cron removes the files. No root, no shell, no privileged helper — see
`01-architecture.md` §6.

## 12. Out of Scope for v1

Present in the schema, deliberately unmodelled for now:

`moderators`, `maillist_owners`, `maillists` (mlmmj), `sender_bcc_domain`,
`recipient_bcc_domain`, `sender_bcc_user`, `recipient_bcc_user`,
`sender_relayhost`, `share_folder`, `anyone_shares`.

They are listed so their absence reads as a decision, not an oversight.

---

## 13. Mailward's Own Entities

Everything below lives in Mailward's database, is fully owned, and is created
by ordinary migrations.

| Entity | Purpose |
|---|---|
| `panel_profiles` | Per-admin panel state, keyed by email address: preferences, last panel login |
| `two_factor_secrets` | TOTP secrets, keyed by email address |
| `audit_log` | Every write Mailward performs: actor, action, target, before/after, IP, timestamp. What "every write" covers is enumerated in `docs/features/audit-log.md` BR-17 and BR-18 |
| `settings` | Instance configuration, including the organisation logo |
| `sessions`, `jobs`, `cache` | Laravel infrastructure |

**BR:** references to mail accounts are plain address strings with no foreign
key — cross-database constraints are impossible. Deleting a mailbox must
explicitly clean up the rows that reference it, except `audit_log`, which is
exempt from that cleanup and deliberately retains references to accounts that no
longer exist.

**BR:** no `audit_log` entry is ever modified, and none is ever deleted
individually. Entries older than a configured retention window are removed
wholesale by a scheduled job, and the default window means "never prune"
(`docs/features/audit-log.md` BR-05 and BR-21;
`docs/reference/decisions-needed.md` Q11, answered 2026-08-15). This replaces
the unqualified "append-only" this section previously asserted: the guarantee
against tampering is unchanged, the guarantee against loss now has a horizon.
