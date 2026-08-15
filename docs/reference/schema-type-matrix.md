# MySQL ↔ PostgreSQL Schema Type Matrix

Status: draft — produced 2026-08-14

Required by `01-architecture.md` §4 before any Eloquent model is written.

## What this is

A column-by-column comparison of the two SQL schema files iRedMail ships for
the `vmail` database, covering only the tables Mailward models in v1
(`02-domain.md` §2–§11).

Read from `github.com/iredmail/iRedMail`, branch `master`, on 2026-08-14:

| Fact | Value |
|---|---|
| Repository HEAD at read time | `641e704140330314fffbb349d11cef83b3e136d2` (2026-08-09) |
| `samples/iredmail/iredmail.mysql` last changed in | `53aa23550e1cf7b7ca8e9ea543e3894d3e33da5b` (2025-03-22) |
| `samples/iredmail/iredmail.pgsql` last changed in | `0c53224eaf63ae76a59c6abb81139e776e86e364` (2026-03-28) |

The two files were last touched twelve months apart. That is worth keeping in
mind: some divergences below may be an unsynchronised edit rather than a
deliberate design choice. Either way Mailward must survive both.

## Warning

**Mailward does not own this schema and never issues DDL against it**
(`01-architecture.md` §2). This document describes what iRedMail creates so the
application can read and write it correctly. Nothing here is a specification
Mailward may change.

It also describes the schema **as freshly installed**. A long-lived server has
been through iRedMail's own upgrade scripts, which are not these files. Before
trusting this matrix against a specific install, verify against that install.

## Reading the tables

- Types are transcribed as the files declare them. MySQL display widths
  (`INT(10)`, `BIGINT(20)`, `TINYINT(1)`) do not affect storage: `TINYINT` is
  8-bit signed, `INT` 32-bit signed, `BIGINT` 64-bit signed. PostgreSQL `INT2`
  is 16-bit, `INT8` 64-bit, `SERIAL` is a 32-bit `integer` plus a sequence.
- `Nullable` and `Default` show a single value when both files agree, and
  `MySQL / PostgreSQL` when they do not.
- `Divergence` is empty when the two declarations are equivalent. Entries
  marked *cosmetic* differ in spelling but not in behaviour.

---

## 1. `domain`

Primary key `domain` in both files.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `description` | TEXT | TEXT | yes / no | NULL / `''` | **D6** nullable on MySQL, `NOT NULL` on PostgreSQL |
| `disclaimer` | TEXT | TEXT | yes / no | NULL / `''` | **D6** same as above |
| `aliases` | INT(10) | INT8 | no | `0` | **D7** 32-bit vs 64-bit |
| `mailboxes` | INT(10) | INT8 | no | `0` | **D7** 32-bit vs 64-bit |
| `maillists` | INT(10) | INT8 | no | `0` | **D7** 32-bit vs 64-bit |
| `maxquota` | BIGINT(20) | INT8 | no | `0` | |
| `quota` | BIGINT(20) | INT8 | no | `0` | |
| `transport` | VARCHAR(255) | VARCHAR(255) | no | `'dovecot'` | |
| `backupmx` | TINYINT(1) | INT2 | no | `0` | **D4** no native boolean on either side |
| `settings` | TEXT | TEXT | yes / no | NULL / `''` | **D6** — iRedAdmin-Pro column, Mailward ignores it |
| `created` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** sentinel vs current time |
| `modified` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** sentinel vs current time |
| `expired` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'9999-12-31 00:00:00'` / `'9999-12-31 01:01:01'` | **D2** different sentinel value |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Indexes match: `backupmx`, `expired`, `active`.

Column **order** differs — MySQL declares `backupmx` before `settings`,
PostgreSQL the reverse (**D18**).

## 2. `alias_domain`

Primary key `alias_domain` in both files. Neither file gives this table an
`expired` column.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `alias_domain` | VARCHAR(255) | VARCHAR(255) | no | none | |
| `target_domain` | VARCHAR(255) | VARCHAR(255) | no | none | |
| `created` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `modified` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Indexes match: `target_domain`, `active`.

## 3. `mailbox`

Primary key `username` in both files. 57 columns in both, in the same order.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `username` | VARCHAR(255) | VARCHAR(255) | no | `''` / none | **D16** MySQL supplies `''`, PostgreSQL has no default |
| `password` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `name` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `language` | VARCHAR(5) | VARCHAR(5) | no | `''` | |
| `first_name` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `last_name` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `mobile` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `telephone` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `recovery_email` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `birthday` | DATE | DATE | no | `'0001-01-01'` | |
| `mailboxformat` | VARCHAR(50) | VARCHAR(50) | no | `'maildir'` | |
| `mailboxfolder` | VARCHAR(50) | VARCHAR(50) | no | `'Maildir'` | |
| `storagebasedirectory` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `storagenode` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `maildir` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `quota` | BIGINT(20) | INT8 | no | `0` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `transport` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `department` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `rank` | VARCHAR(255) | VARCHAR(255) | no | `'normal'` | **D19** MySQL must quote the identifier; PostgreSQL declares it bare |
| `employeeid` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `isadmin` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `isglobaladmin` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `enablesmtp` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesmtpsecured` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablepop3` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablepop3secured` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablepop3tls` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enableimap` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enableimapsecured` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enableimaptls` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enabledeliver` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablelda` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablemanagesieve` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablemanagesievesecured` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesieve` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesievesecured` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesievetls` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enableinternal` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enabledoveadm` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablelib-storage` | TINYINT(1) | INT2 | no | `1` | **D4**; **D19** hyphenated identifier, quoting differs per driver |
| `enablequota-status` | TINYINT(1) | INT2 | no | `1` | **D4**; **D19** |
| `enableindexer-worker` | TINYINT(1) | INT2 | no | `1` | **D4**; **D19** |
| `enablelmtp` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enabledsync` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesogo` | TINYINT(1) | INT2 | no | `1` | **D4** |
| `enablesogowebmail` | CHAR(1) | VARCHAR(1) | no | `'y'` | **D5** character flag, not an integer; *cosmetic* type spelling |
| `enablesogocalendar` | CHAR(1) | VARCHAR(1) | no | `'y'` | **D5**; *cosmetic* |
| `enablesogoactivesync` | CHAR(1) | VARCHAR(1) | no | `'y'` | **D5**; *cosmetic* |
| `allow_nets` | TEXT | TEXT | yes | NULL | |
| `disclaimer` | TEXT | TEXT | yes / no | NULL / `''` | **D6** |
| `settings` | TEXT | TEXT | yes / no | NULL / `''` | **D6** — iRedAdmin-Pro column, Mailward ignores it |
| `passwordlastchange` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `created` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `modified` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `expired` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'9999-12-31 00:00:00'` / `'9999-12-31 01:01:01'` | **D2** |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Both files declare the same 31 secondary indexes on this table. No divergence.

Two facts that are *not* divergences but bite on both drivers:

- Both files comment that the three SOGo columns require a character type, not
  an integer.
- Both files comment that `allow_nets` must be NULL — not `''` — when the
  account is unrestricted. It is the only nullable TEXT column that agrees
  across the two files.
- Both files default `birthday` to `'0001-01-01'`. Whether a given MySQL
  server's `sql_mode` accepts that value is a server property, not a schema
  property — see Unverified.

## 4. `forwardings`

Surrogate key `id`; unique on `(address, forwarding)` in both files.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `id` | BIGINT(20) UNSIGNED AUTO_INCREMENT | SERIAL | no | auto | **D9** 64-bit unsigned vs 32-bit signed sequence |
| `address` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `forwarding` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `dest_domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `is_maillist` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `is_list` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `is_forwarding` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `is_alias` | TINYINT(1) | INT2 | no | `0` | **D4** |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Primary key: `id` in both. Unique index on `(address, forwarding)` in both.

Secondary indexes **do not match** (**D15**):

| Index on | MySQL | PostgreSQL |
|---|---|---|
| `address` alone | no (leftmost prefix of the unique index) | yes |
| `forwarding` | yes | yes |
| `domain` | yes | yes |
| `dest_domain` | yes | yes |
| `is_maillist` | yes | yes |
| `is_list` | yes | yes |
| `is_alias` | yes | yes |
| `is_forwarding` | **no** | yes |

## 5. `alias`

Primary key `address` in both files.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `address` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `name` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `accesspolicy` | VARCHAR(30) | VARCHAR(30) | no | `''` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `created` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `modified` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `expired` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'9999-12-31 00:00:00'` / `'9999-12-31 01:01:01'` | **D2** |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Indexes match: `domain`, `expired`, `active`.

## 6. `domain_admins`

Composite primary key `(username, domain)` in both files.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `username` | VARCHAR(255) CHARACTER SET ascii | VARCHAR(255) | no | `''` | **D8** ASCII-only on MySQL; no charset clause on PostgreSQL |
| `domain` | VARCHAR(255) CHARACTER SET ascii | VARCHAR(255) | no | `''` | **D8** same |
| `created` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `modified` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'1970-01-01 01:01:01'` / `NOW()` | **D1** |
| `expired` | DATETIME | TIMESTAMP WITHOUT TIME ZONE | no | `'9999-12-31 00:00:00'` / `'9999-12-31 01:01:01'` | **D2** |
| `active` | TINYINT(1) | INT2 | no | `1` | **D4** |

Indexes match: `username`, `domain`, `active`.

The MySQL table's own default is `utf8mb4` / `utf8mb4_general_ci`; only these
two columns override it to `ascii`. Neither file states an explicit `COLLATE`
for them.

## 7. `used_quota`

Primary key `username` in both files. Read-only for Mailward — both files carry
the same comment that Dovecot maintains this table.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `username` | VARCHAR(255) | VARCHAR(255) | no | none | |
| `bytes` | BIGINT | INT8 | no | `0` | |
| `messages` | BIGINT | INT8 | no | `0` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | **D14** populated by a trigger on MySQL only |

Index on `domain` in both.

**Trigger (MySQL only, D14).** The MySQL file defines a `BEFORE INSERT` trigger
named `used_quota_before_insert` that fills `domain` with the portion of
`username` after the last `@`. Its stated purpose is to make per-domain quota
aggregation cheap. The PostgreSQL file defines **no trigger and no function at
all**; it contains a comment on a different table (`last_login`) explaining
that a composite primary key was chosen there specifically to avoid needing a
trigger. Nothing in the PostgreSQL file fills `used_quota.domain`.

## 8. `last_login`

Read-only for Mailward.

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `username` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `imap` | INT(11) | BIGINT | yes | NULL | **D13** 32-bit vs 64-bit Unix timestamp |
| `pop3` | INT(11) | BIGINT | yes | NULL | **D13** |
| `lda` | INT(11) | BIGINT | yes | NULL | **D13** |

**Primary key differs (D12):** `(username)` on MySQL, `(username, domain)` on
PostgreSQL. The PostgreSQL file comments that the composite key exists because
Dovecot inserts both columns and uses them as its unique index.

Indexes otherwise match: `domain`, `imap`, `pop3`, `lda`.

Note `domain` exists in both files; `02-domain.md` §10 lists only `imap`,
`pop3` and `lda`.

## 9. `deleted_mailboxes`

| Column | MySQL type | PostgreSQL type | Nullable | Default | Divergence |
|---|---|---|---|---|---|
| `id` | BIGINT(20) UNSIGNED AUTO_INCREMENT | SERIAL | no | auto | **D9** 64-bit unsigned vs 32-bit signed; **D10** key differs, see below |
| `timestamp` | TIMESTAMP | TIMESTAMP WITHOUT TIME ZONE | no | `CURRENT_TIMESTAMP` | **D11** MySQL `TIMESTAMP` is time-zone-converted and epoch-limited |
| `username` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `domain` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `maildir` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `bytes` | BIGINT | INT8 | no | `0` | |
| `messages` | BIGINT | INT8 | no | `0` | |
| `admin` | VARCHAR(255) | VARCHAR(255) | no | `''` | |
| `delete_date` | DATE | DATE | yes | NULL | |

**Primary key differs (D10).** The PostgreSQL file declares `id SERIAL PRIMARY
KEY`. The MySQL file declares **no primary key and no unique index**; `id` is
auto-increment backed only by an ordinary non-unique index, which is the
minimum MySQL requires for an auto-increment column. On MySQL this table has no
unique constraint on any column.

Indexes otherwise match: `timestamp`, `username`, `domain`, `admin`,
`delete_date`.

Both files comment that an external cron job performs the actual filesystem
removal.

---

## Divergences that affect the application

Nineteen, ordered roughly by how much damage they do.

**D14 — `used_quota.domain` is filled by a trigger on MySQL and by nothing on
PostgreSQL.** Any query that groups or filters `used_quota` by `domain` returns
correct per-domain totals on MySQL and zero rows — or an empty-string bucket —
on PostgreSQL; per-domain usage must instead be derived by correlating
`used_quota.username` against `mailbox`, on both drivers.

**D1 — `created` / `modified` / `passwordlastchange` default to the
`1970-01-01 01:01:01` sentinel on MySQL and to `NOW()` on PostgreSQL.** The
"never set" sentinel documented in `02-domain.md` §1.2 simply does not exist on
PostgreSQL, so a cast or accessor that maps the sentinel to `null` must not be
relied on to detect "never changed"; Mailward must write these columns
explicitly on every insert rather than letting the column default fire.

**D2 — the `expired` "never expires" sentinel is `9999-12-31 00:00:00` on MySQL
and `9999-12-31 01:01:01` on PostgreSQL.** Comparing `expired` against a
hard-coded sentinel constant is wrong on one of the two drivers; expiry must be
tested as `expired > now()` and the sentinel written per-driver, never compared
for equality.

**D4 — no boolean column exists on either side: MySQL uses `TINYINT(1)`,
PostgreSQL `INT2`.** Every flag needs an explicit `'boolean'` cast in the model,
and query builder comparisons must bind `1`/`0`, not `true`/`false` —
PostgreSQL has no implicit cast between `smallint` and `boolean` and will error
on `active = true`, where MySQL accepts it silently.

**D12 — `last_login` has primary key `(username)` on MySQL and
`(username, domain)` on PostgreSQL.** Eloquent does not support composite
primary keys, so the model must not declare a key it can use for `find()` on
both drivers; look-ups have to go through an explicit `where('username', …)`
and the model must be treated as read-only with `$incrementing = false`.

**D10 — `deleted_mailboxes` has a `SERIAL PRIMARY KEY` on PostgreSQL and no
primary key or unique index at all on MySQL.** `Model::find()`, `save()` on a
retrieved row, and any `updateOrCreate` are unsafe on MySQL because nothing
guarantees `id` is unique; Mailward should insert into this table and never
update by key.

**D5 — the three SOGo columns are `CHAR(1)` on MySQL and `VARCHAR(1)` on
PostgreSQL, both holding `'y'`/`'n'`.** They must never carry Eloquent's
`'boolean'` cast, which would write `1` and break SOGo; they need a dedicated
cast converting PHP `bool` to the literal characters. The `CHAR` versus
`VARCHAR` spelling itself is behaviourally irrelevant for single non-space
characters.

**D6 — `description`, `disclaimer` and `settings` are nullable TEXT on MySQL and
`NOT NULL DEFAULT ''` on PostgreSQL.** Writing PHP `null` to any of them
succeeds on MySQL and raises a not-null violation on PostgreSQL, so Mailward
must normalise these to `''` on write and treat `''` and `null` as the same
absent value on read. `mailbox.allow_nets` is the exception — nullable in both,
and both files require NULL rather than `''`.

**D8 — `domain_admins.username` and `domain_admins.domain` are `CHARACTER SET
ascii` on MySQL and plain `VARCHAR` on PostgreSQL.** On MySQL an
internationalised address or IDN domain cannot be stored here at all, and any
join from `domain_admins.domain` to `domain.domain` mixes an `ascii` column
with a `utf8mb4_general_ci` one — MySQL raises an illegal-mix-of-collations
error unless one side is explicitly converted, so cross-table admin scoping
queries must be tested on MySQL specifically.

**D13 — `last_login.imap` / `pop3` / `lda` are 32-bit `INT` on MySQL and
`BIGINT` on PostgreSQL.** These hold Unix timestamps, so MySQL installs overflow
in January 2038; Mailward must not compute or write into these columns, and any
dormant-account report must tolerate a negative or wrapped value on MySQL.

**D11 — `deleted_mailboxes.timestamp` is MySQL's `TIMESTAMP` type but
PostgreSQL's `TIMESTAMP WITHOUT TIME ZONE`.** MySQL converts the value between
the session time zone and UTC on every read and write while PostgreSQL stores
it verbatim, so the same row read through two drivers can differ by the server's
UTC offset; Mailward must set an explicit session time zone or ignore this
column in favour of `delete_date`.

**D9 — surrogate keys are `BIGINT UNSIGNED AUTO_INCREMENT` on MySQL and
`SERIAL` (32-bit signed) on PostgreSQL, on `forwardings` and
`deleted_mailboxes`.** The PostgreSQL sequence exhausts at 2,147,483,647 where
MySQL does not, and the model's key type must be `int` rather than `bool`-safe
`string` while never assuming the two drivers produce comparable id ranges.

**D7 — `domain.aliases`, `domain.mailboxes` and `domain.maillists` are 32-bit
`INT` on MySQL and 64-bit `INT8` on PostgreSQL.** Validation on the limit fields
must cap at the 32-bit signed maximum to avoid an out-of-range write that
succeeds on PostgreSQL and fails or truncates on MySQL.

**D15 — MySQL has no index on `forwardings.is_forwarding`; PostgreSQL does.**
The self-forwarding lookup that `02-domain.md` §5 makes mandatory for every
mailbox is unindexed on MySQL, so queries must always be anchored on `address`
or `domain` — which are indexed on both — and never filter on the flag alone.

**D16 — `mailbox.username` has a `''` default on MySQL and no default on
PostgreSQL.** An insert that omits `username` silently creates a row with an
empty address on MySQL and errors on PostgreSQL; the column must always be set
explicitly, never left to the database.

**D18 — `domain` declares `settings` and `backupmx` in opposite order in the two
files.** Only positional result access (`PDO::FETCH_NUM`, `SELECT *` with
ordinal reads) is affected; Mailward must always name columns explicitly, which
Eloquent does by default.

**D19 — identifier quoting differs: `rank` is backtick-quoted on MySQL and bare
on PostgreSQL, and the three hyphenated `enable*` columns require quoting on
both but with different characters.** Any raw SQL or `whereRaw` touching these
columns is driver-specific; they must be accessed through the query builder so
Laravel's grammar quotes them, and `rank` must be listed in `$fillable` under
that exact name.

**D17 — MySQL declares `utf8mb4_general_ci` on every in-scope table; the
PostgreSQL file declares no collation at all.** Address comparison is therefore
case-insensitive on MySQL and case-sensitive on PostgreSQL under the usual
`C`/`en_US.UTF-8` cluster defaults — this is exactly the failure
`0005-lowercase-canonical-addresses.md` exists to prevent, and it means a
uniqueness check that passes on MySQL can admit a duplicate on PostgreSQL.

**D3 — date columns are `DATETIME` on MySQL and `TIMESTAMP WITHOUT TIME ZONE` on
PostgreSQL.** Both are naive types with no time zone, so the practical
consequence is limited to range and to the value each driver returns as a
string; the models must declare an explicit `datetime` cast rather than relying
on driver-native date objects.

---

## Unverified

Determined by neither file. Each must be confirmed against the disposable
iRedMail VM described in `01-architecture.md` §1 before being relied on.

1. **The PHP type each driver actually returns.** Whether PDO hands back
   `TINYINT(1)`, `INT2`, `INT8` and `BIGINT` as PHP `int` or as `string`, per
   driver and per `PDO::ATTR_EMULATE_PREPARES` setting. This decides whether
   strict comparisons against `1` are safe.
2. **Whether MySQL adds an implicit `ON UPDATE CURRENT_TIMESTAMP` to
   `deleted_mailboxes.timestamp`.** The file specifies a default but no
   `ON UPDATE` clause; the resulting behaviour depends on the server's
   `explicit_defaults_for_timestamp` setting and MySQL version.
3. **The PostgreSQL collation actually in force.** The file specifies none, so
   it inherits whatever the cluster and database were created with. D17's
   consequence depends on that value.
4. **The effective collation of the two `ascii` columns on MySQL.** The file
   specifies the character set but no `COLLATE`, so the collation is the
   charset default for that server version.
5. **Whether a given MySQL server's `sql_mode` accepts `'0001-01-01'` for
   `mailbox.birthday`** and the `9999-12-31` values for `expired`.
6. **Whether a real PostgreSQL install populates `used_quota.domain` by some
   means outside this file** — an upgrade script, a Dovecot configuration, or a
   trigger added post-install. The file itself provides nothing.
7. **What the schema looks like after iRedMail's upgrade scripts.** These files
   describe a fresh install only. An upgraded server may have different types,
   defaults or indexes on any of the nine tables.
8. **Which iRedMail release each file corresponds to.** Neither file carries a
   version stamp; only the commit dates above are known.
9. **Whether the divergences are intentional.** The two files were last changed
   twelve months apart, so any given divergence may be a pending sync rather
   than a design decision, and may be resolved in either direction upstream.
10. **`alias.accesspolicy` and `mailbox.transport` permitted values.** Both are
    free-text `VARCHAR` in both files; nothing constrains them at the schema
    level.
11. **The PostgreSQL sequence names and ownership for the `SERIAL` columns**,
    which matter only if Mailward ever needs `lastInsertId` on that driver.

---

## Provenance

Both files were read directly from `raw.githubusercontent.com` at the commits
recorded above, for the purpose of learning the data model. Per
`0006-mit-license.md`, no SQL was copied into this document — every statement
here is a factual description of column names, types, defaults, constraints and
indexes. No iRedAdmin-Pro source was consulted.
