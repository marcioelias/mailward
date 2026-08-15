# Open Questions — Research

Status: draft — research only, nothing here is confirmed against a real install

Scope: OQ-02, OQ-03 and OQ-04 from `docs/00-overview.md` §9. These three block
the first feature.

**Sourcing rule applied here.** Only iRedMail's official documentation site,
its public schema, its public configuration/sample files, its release notes and
its public forum were consulted, plus Dovecot's own documentation. No
iRedAdmin-Pro source was consulted, and no code was copied
(`docs/decisions/0006-mit-license.md`). Every factual claim below carries the
URL it came from. Anything without a URL is a hypothesis or is listed as
unknown.

**Reading order.** "What is established" is safe to design against. "Hypothesis"
is not — it is the thing the verification procedure exists to kill or confirm.

---

## OQ-02 — How is a global admin represented in `domain_admins`?

**Question** — Does a global admin also get a `domain_admins` row (with a
sentinel domain such as `ALL`), or does `mailbox.isglobaladmin = 1` alone
suffice for the scope query in `docs/policies/authorization.md` §3?

### What is established

- iRedMail's official documentation promotes a mail user to global admin with
  **two** writes: a flag update on `mailbox` **and** an insert into
  `domain_admins` with the literal domain value `ALL`:

  ```sql
  USE vmail;
  UPDATE mailbox SET isadmin=1, isglobaladmin=1 WHERE username='john@example.com';
  INSERT INTO domain_admins (username, domain) VALUES ('john@example.com', 'ALL');
  ```

  The page states the special domain value `"ALL"` designates global
  administrator privileges.
  — https://docs.iredmail.org/promote.user.to.be.global.admin.html

- The same page documents the per-domain case as `isadmin=1`,
  `isglobaladmin=0`, plus a `domain_admins` row carrying the **real** domain
  name. So `ALL` is a sentinel occupying the same column as real domain names.
  — https://docs.iredmail.org/promote.user.to.be.global.admin.html

- The same page states: *"iRedAdmin open source edition supports only global
  admin, no per-domain admin. iRedAdmin-Pro supports both."* Consequence for
  Mailward: the open source panel most administrators have been using never
  exercised the per-domain path, so real installs may contain very few
  `domain_admins` rows other than the `ALL` ones.
  — https://docs.iredmail.org/promote.user.to.be.global.admin.html

- `domain_admins` has primary key `(username, domain)`, both columns
  `CHARACTER SET ascii`, and carries its own `active`, `created`, `modified`
  and `expired` columns:

  ```sql
  CREATE TABLE IF NOT EXISTS domain_admins (
      username VARCHAR(255) CHARACTER SET ascii NOT NULL DEFAULT '',
      domain VARCHAR(255) CHARACTER SET ascii NOT NULL DEFAULT '',
      created DATETIME NOT NULL DEFAULT '1970-01-01 01:01:01',
      modified DATETIME NOT NULL DEFAULT '1970-01-01 01:01:01',
      expired DATETIME NOT NULL DEFAULT '9999-12-31 00:00:00',
      active TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (username,domain), ...
  ```

  — https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql

  Two consequences that are schema facts, not opinions: the sentinel `ALL` can
  only ever appear **once per admin** (primary key), and a `domain_admins` row
  can be **inactive** (`active = 0`) independently of `mailbox.active`.

- A fresh install already carries the row. A forum post reports that re-running
  the documented promotion on an install whose `postmaster@` was already set up
  fails with:

  > `ERROR 1062 (23000): Duplicate entry 'postmaster@xxxxx.com-ALL' for key 'domain_admins.PRIMARY'`

  i.e. the `(postmaster@…, ALL)` pair already existed before the admin ran
  anything.
  — https://forum.iredmail.org/post91217.html

### Hypothesis (unconfirmed)

1. On a stock install, a global admin is represented **redundantly**:
   `mailbox.isglobaladmin = 1` *and* a `domain_admins` row with `domain = 'ALL'`.
   Both are written by iRedMail's own tooling; neither is derived from the other.
2. `ALL` is a **case-sensitive-looking literal but stored in a
   case-insensitive-collated column on MySQL**, so `'all'` and `'ALL'` would
   compare equal there and not on PostgreSQL — the same trap as §1.1 of
   `docs/02-domain.md`.
3. Because the two representations can drift (someone sets `isglobaladmin = 0`
   and leaves the `ALL` row, or vice versa), **exactly one of them must be
   authoritative for Mailward**, and it should be `mailbox.isglobaladmin`, per
   `docs/policies/authorization.md` §1 — with the `ALL` row treated as a
   companion record that Mailward *maintains* for compatibility with iRedAdmin
   running alongside (`docs/decisions/0003`, "drop-in replacement"), never as an
   input to the authorization decision.
4. Therefore the domain-scope query **can** trust one flag, but the *write* path
   (promote/demote) **cannot** touch only one table.
5. A `domain_admins` row whose `domain` is `ALL` must be excluded from any join
   against `domain.domain`, or a domain admin listing will silently produce zero
   rows for a global admin. Any query of the form
   `domain_admins JOIN domain ON domain_admins.domain = domain.domain` is
   suspect by construction.

### How to confirm

On the disposable iRedMail VM (`docs/01-architecture.md` §1, local
development), immediately after a fresh install and **before** creating
anything:

```sql
-- 1. Does the installer write the sentinel row at all?
SELECT username, domain, active, created FROM vmail.domain_admins ORDER BY username, domain;

-- 2. Do the two representations agree on a stock install?
SELECT m.username, m.isadmin, m.isglobaladmin, da.domain AS admin_row_domain, da.active
FROM vmail.mailbox m
LEFT JOIN vmail.domain_admins da ON da.username = m.username
WHERE m.isadmin = 1 OR m.isglobaladmin = 1 OR da.username IS NOT NULL;

-- 3. Exact literal and case actually stored.
SELECT DISTINCT domain, LENGTH(domain), HEX(domain) FROM vmail.domain_admins;   -- MySQL
SELECT DISTINCT domain, length(domain) FROM vmail.domain_admins;                -- PostgreSQL

-- 4. Is 'ALL' ever also a real domain? (must return 0 rows)
SELECT * FROM vmail.domain WHERE domain = 'ALL';
```

Confirms the hypothesis if: query 1 returns exactly one row,
`(postmaster@<first-domain>, 'ALL')`; query 2 shows that same account with
`isadmin = 1` and `isglobaladmin = 1`; query 3 shows the literal is uppercase
`ALL`, 3 bytes; query 4 returns nothing.

Refutes it if: query 1 returns **no** rows (then `isglobaladmin` alone is the
whole story and Mailward must not write a companion row), or query 3 shows a
different sentinel (e.g. `*`, empty string, or lowercase `all`).

Then, second pass — behavioural, and the part that actually decides the write
path:

```sql
-- Break the two representations apart deliberately and see what iRedAdmin does.
UPDATE vmail.mailbox SET isglobaladmin=0 WHERE username='postmaster@example.com';
-- log in to iRedAdmin: still global admin?  (the ALL row is still there)
UPDATE vmail.mailbox SET isglobaladmin=1 WHERE username='postmaster@example.com';
DELETE FROM vmail.domain_admins WHERE username='postmaster@example.com' AND domain='ALL';
-- log in to iRedAdmin again: still global admin?  (only the flag is left)
```

Whichever of the two, alone, still grants access is what iRedMail's own panel
treats as authoritative. Record the answer; Mailward's *read* path follows
`docs/policies/authorization.md` §1 regardless, but the *write* path must
maintain whatever iRedAdmin reads, or the two panels will disagree while
running side by side.

Finally, repeat query 1 after using iRedAdmin (open source) to promote a second
account, to see whether the panel writes the same pair the docs do.

### What it blocks

- The global scope in `docs/policies/authorization.md` §3 — whether the default
  global scope on mail models reads one column or must join `domain_admins`.
- The `domain_admins` model and its default scope, including whether `ALL` must
  be filtered out of every domain-list query.
- The promote/demote Action, and BR-A01/BR-A02 in
  `docs/policies/authorization.md` §5 — "the last global admin" cannot be
  counted until it is known what defines one.
- `php artisan mailward:promote <address> --global` (BR-A04): it must write
  exactly the rows iRedMail expects, or the escape hatch does not work.
- The `active` semantics of a `domain_admins` row: whether Mailward must treat
  `domain_admins.active = 0` as a revoked grant. Nothing sourced says iRedMail
  reads it.

---

## OQ-03 — What algorithm generates `mailbox.maildir`?

**Question** — What is the observable shape of the `maildir` value, and which
iRedMail settings determine it?

> Per `docs/decisions/0006-mit-license.md`, the algorithm is to be re-derived
> from observed behaviour on a real install. This section deliberately stops at
> shape and inputs. It contains no implementation and no transcription of
> iRedMail's generator.

### What is established

**1. `maildir` is not the whole path — it is the last segment of a three-part
concatenation.** Dovecot's user lookup, as shipped by iRedMail, builds the
account's home directory like this:

```
SELECT LOWER('%u') AS master_user,
       LOWER(CONCAT(mailbox.storagebasedirectory, '/', mailbox.storagenode, '/', mailbox.maildir)) AS home,
       CONCAT(mailbox.mailboxformat, ':~/', mailbox.mailboxfolder) AS mail,
       CONCAT('*:bytes=', mailbox.quota*1048576) AS quota_rule
FROM mailbox,domain WHERE ...
```

— https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot-sql.conf

The same concatenation is given in the official migration guide as the way to
obtain a user's full maildir path:

```sql
SELECT CONCAT(storagebasedirectory, '/', storagenode, '/', maildir) FROM mailbox WHERE username='user@domain.com';
```

— https://docs.iredmail.org/migrate.to.new.iredmail.server.html

> **Contradiction to raise against our own docs.** `docs/02-domain.md` §4 and
> the glossary in `docs/00-overview.md` §6 describe `maildir` as an *absolute
> path*. The two sources above say it is the **relative** tail below
> `storagebasedirectory/storagenode`. The schema itself does not annotate the
> `mailbox.maildir` column at all
> (https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql).
> Note that the *other* `maildir` column, on `deleted_mailboxes`, **is**
> annotated `-- Absolute path of user's mailbox` in that same schema file —
> which is very likely where the confusion originated. Older iRedMail releases
> may also have stored an absolute value here. This must be settled by
> observation (procedure below) before `docs/02-domain.md` is corrected; this
> document does not edit it.

**2. Dovecot lowercases the assembled home path.** The `LOWER(...)` around the
concatenation is Dovecot's, not ours — so a `maildir` value containing
uppercase resolves to a lowercase directory on disk.
— https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot-sql.conf

**3. The mail folder is appended separately, relative to home:**
`mailboxformat + ':~/' + mailboxfolder` → by default `maildir:~/Maildir`. The
shipped `dovecot.conf` fallback is
`mail_location = maildir:%Lh/Maildir/:INDEX=%Lh/Maildir/`.
— https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot.conf

So `mailbox.maildir` must **not** include `Maildir`. The value is the account
directory; Dovecot appends the folder.

**4. The two path components above `maildir` come from install-time settings:**

```
# Directory used to store mailboxes
export STORAGE_BASE_DIR="${STORAGE_BASE_DIR:=/var/vmail}"

# Mailboxes will be stored under sub-directory ${STORAGE_NODE} of vmail user's
# home directory. e.g. home directory is /var/vmail, the mailboxes will be
# /var/vmail/vmail1.
export STORAGE_NODE="${STORAGE_NODE:=vmail1}"

# Maildir style: hashed, normal.
export MAILDIR_STYLE='hashed'
```

— https://github.com/iredmail/iRedMail/blob/master/conf/global

**5. The default layout is hashed, and the official shape is
`domain / <hash levels> / <localpart><separator><timestamp>`.** iRedMail's own
documentation for its user-creation tool states:

> *"Maildir path is hashed like `domain.ltd/u/s/e/username-20150929`. If you
> prefer `domain.ltd/username/`, please set `MAILDIR_STYLE='normal'`."*

— https://docs.iredmail.org/sql.create.mail.user.html
— https://docs.iredmail.org/sql.bulk.create.mail.users.html

**6. Four independent switches shape the value**, documented (for the Pro
panel's settings, but naming the same four concepts) with these defaults:

| Setting | Default | Effect on the value |
|---|---|---|
| `MAILDIR_HASHED` | `True` | inserts the intermediate hash levels |
| `MAILDIR_PREPEND_DOMAIN` | `True` | prefixes the domain name as the first level |
| `MAILDIR_APPEND_TIMESTAMP` | `True` | appends a timestamp to the account directory |
| `storage_base_directory` | `/var/vmail/vmail1` | the part *above* `maildir` |

Examples given there: `domain.ltd/u/s/e/username-2009.09.04.12.05.33/` (hashed),
`domain.ltd/username-2009.09.04.12.05.33/` (not hashed),
`domain.ltd/username/` (no timestamp).
— https://docs.iredmail.org/iredadmin-pro.customize.maildir.path.html

**7. The timestamp format is producer-specific, not schema-defined.** The two
official pages show two different formats for the same field: `20150929`
(https://docs.iredmail.org/sql.create.mail.user.html) and
`2009.09.04.12.05.33`
(https://docs.iredmail.org/iredadmin-pro.customize.maildir.path.html). Nothing
consumes the timestamp — Dovecot only concatenates the stored string — so it is
a uniqueness/disambiguation device, not a parsed field.

**8. `storage_base_directory` is documented with two different meanings.**
`conf/global` splits `/var/vmail` (base) from `vmail1` (node), while the
user-creation tool and the Pro panel both document a single
`STORAGE_BASE_DIRECTORY` / `storage_base_directory` defaulting to
`/var/vmail/vmail1` — i.e. base **and** node together. How that single value is
split across the two DB columns is not stated on any page consulted.
— https://github.com/iredmail/iRedMail/blob/master/conf/global
— https://docs.iredmail.org/sql.create.mail.user.html
— https://docs.iredmail.org/iredadmin-pro.customize.maildir.path.html

### Hypothesis (unconfirmed)

1. On a stock install: `storagebasedirectory = '/var/vmail'`,
   `storagenode = 'vmail1'`, and `maildir` holds the **relative** remainder,
   ending with a trailing `/`, e.g.
   `example.com/p/o/s/postmaster-2026.08.14.10.30.00/`.
2. The hash levels are derived from the **local part only** (not the domain),
   taking successive leading characters, one character per level, three levels —
   the shape `u/s/e` for `username`. A competing description of the same
   feature, seen only in aggregated search text and never in a page we could
   read directly, gives cumulative prefixes `u/us/use`. **Both shapes are
   plausible and they are indistinguishable for a 1-character-per-level reading
   of a single example.** This is the single most important thing the
   verification below must settle, and it must be settled by creating accounts
   with deliberately awkward local parts.
3. Local parts shorter than the number of hash levels, and local parts whose
   leading characters are not `[a-z0-9]` (dots, hyphens, underscores, plus
   signs, non-ASCII), are handled by some padding or substitution rule.
   **Nothing sourced describes it.**
4. The trailing separator between local part and timestamp is `-`.
5. Because Dovecot lowercases `home`, and because addresses are canonically
   lowercase anyway (`docs/decisions/0005`), the generated value is entirely
   lowercase.
6. **Mailward may not have to reproduce the algorithm byte-for-byte at all.**
   Nothing in the schema or in Dovecot's queries parses `maildir`; it is an
   opaque string. If Dovecot creates the directory on first delivery, then any
   *unique, collision-free, lowercase, filesystem-safe* relative path works, and
   matching iRedMail's shape is a matter of operational consistency (so that
   `du`, backups and iRedAdmin-created accounts all look alike), not of
   correctness. If Dovecot does **not** auto-create it, the path must exist on
   disk before delivery, and Mailward — running unprivileged, per
   `docs/01-architecture.md` §6 — cannot create it, which turns OQ-03 into a
   privileged-operation problem rather than a string-formatting problem.
   **This is the decisive question and it is not answered by any source
   consulted.**

### How to confirm

**Step 1 — read what a stock install actually stored.**

```sql
SELECT username, storagebasedirectory, storagenode, maildir, mailboxformat, mailboxfolder
FROM vmail.mailbox;
```

Confirms hypothesis 1 if the three columns split as `/var/vmail` + `vmail1` +
relative remainder. Refutes it if `maildir` starts with `/`.

Cross-check against the filesystem — this is the authoritative test, because it
is what Dovecot resolves:

```bash
sudo find /var/vmail -maxdepth 6 -type d -name 'Maildir' | head
sudo doveadm user -f home postmaster@example.com
sudo ls -la "$(sudo doveadm user -f home postmaster@example.com)"
```

`doveadm user` runs iRedMail's real `user_query`, so its `home` output is
ground truth for the concatenation.

**Step 2 — settle the hash shape with adversarial local parts.**

Create accounts with iRedMail's own tool (not by hand), then read back only the
generated column. Local parts chosen to discriminate between `u/s/e` and
`u/us/use` and to expose the short/odd-character rules:

```bash
cd /path/to/iRedMail-x.y.z/tools/
bash create_mail_user_SQL.sh abcdef@example.com 'pw' > /tmp/u1.sql
bash create_mail_user_SQL.sh ab@example.com     'pw' > /tmp/u2.sql
bash create_mail_user_SQL.sh a@example.com      'pw' > /tmp/u3.sql
bash create_mail_user_SQL.sh a.b-c@example.com  'pw' > /tmp/u4.sql
bash create_mail_user_SQL.sh 1user@example.com  'pw' > /tmp/u5.sql
bash create_mail_user_SQL.sh UPPER@example.com  'pw' > /tmp/u6.sql
grep -o "maildir[^,]*" /tmp/u*.sql
```

Reading the generated SQL is enough and is preferable to importing it. Then:

- `abcdef` → `a/b/c/` means one character per level; `a/ab/abc/` means
  cumulative prefixes. This is the discriminator.
- `ab` and `a` expose the short-local-part rule (padding character? fewer
  levels? a fixed fallback?).
- `a.b-c` and `1user` expose the non-alphanumeric and digit rules.
- `UPPER` shows whether the tool lowercases.

Repeat the whole set with `MAILDIR_STYLE='normal'` exported, and with the
timestamp switch off if the tool exposes one, to confirm which switch removes
which segment.

Also create one account through **iRedAdmin (open source)** and compare its
`maildir` with the shell tool's for a similar address — if the two producers
already disagree in shape (they demonstrably disagree on timestamp format, see
established fact 7), then shape-matching is confirmed to be cosmetic and
hypothesis 6 gains a lot of weight.

**Step 3 — the decisive test: does Dovecot create the directory?**

Insert a mailbox row by hand (plus its self-referencing `forwardings` row —
`docs/02-domain.md` §5) with a `maildir` value whose directory does **not**
exist on disk, then deliver to it:

```bash
sudo ls /var/vmail/vmail1/example.com/        # note what exists before
# ... insert mailbox + forwardings rows, maildir = 'example.com/probe-mailward/'
echo test | sudo /usr/lib/dovecot/dovecot-lda -d probe@example.com   # path varies by distro
sudo ls -la /var/vmail/vmail1/example.com/probe-mailward/
sudo doveadm user -f home probe@example.com
```

If `Maildir/new` appears under the new directory and the message is delivered,
Dovecot auto-creates, hypothesis 6 holds, and Mailward is free to generate the
path itself with no privileged helper. If delivery fails, capture the exact log
line from the mail log — that error is the requirement statement for the
privileged-helper design in `docs/01-architecture.md` §6.

Also verify the reverse direction: log in over IMAP as a newly created account
and confirm the mailbox is usable, not merely that a directory exists.

**Step 4 — record the environment.** `cat /etc/iredmail-release` and store the
version alongside every observation; iRedMail stores the release version there
after installation
(https://docs.iredmail.org/upgrade.iredmail.1.7.2-1.7.3.html). Every finding in
this section is version-scoped, and is scoped by the supported range now
declared in `docs/00-overview.md` §8 (1.7.3 and later, validated against 1.8.4).

### What it blocks

- The create-mailbox Action in its entirety. Without a `maildir` value there is
  no valid `mailbox` insert (`docs/02-domain.md` §4).
- Whether mailbox creation needs a privileged helper at all
  (`docs/01-architecture.md` §6) — i.e. whether the whole product stays "plain
  SQL, unprivileged", which is a load-bearing architectural claim.
- Whether Mailward may offer a storage-node choice on the create form, and what
  it writes into `storagebasedirectory` / `storagenode`.
- Mailbox deletion via `deleted_mailboxes` (`docs/02-domain.md` §11): that
  table's `maildir` is documented as an **absolute** path
  (https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql),
  so if `mailbox.maildir` is relative, Mailward must concatenate the three
  columns when writing the deletion row — get this wrong and iRedMail's cron
  either deletes nothing or, worse, resolves a path we did not intend.
- The correction of `docs/02-domain.md` §4 and the `docs/00-overview.md` §6
  glossary entry for "Maildir".

---

## OQ-04 — Which password schemes must be verifiable, and how is the generating scheme detected?

**Question** — Which schemes may exist on legacy accounts and must be
*verifiable*, and how does Mailward learn at runtime which scheme the server is
configured to *generate*?

### What is established

**1. The prefix convention.** Hashes in `mailbox.password` carry a `{SCHEME}`
prefix. iRedMail's page lists, with real examples:

| Scheme | Example value in the column |
|---|---|
| SSHA512 | `{SSHA512}FxgXDhBVYmTqoboW+ibyyzPv/wGG7y4VJtuHWrx+wfqrs/lIH2Qxn2eA0jygXtBhMvRi7GNFmL++6aAZ0kXpcy1fxag=` |
| BCRYPT | `{CRYPT}$2a$05$TKnXV39M3uJ4o.AbY1HbjeAval9bunHbxd0.6Qn782yKoBjTEBXTe` |
| SSHA | `{SSHA}OuCrqL2yWwQIu8a9uvyOQ5V/ZKfL7LJD` |
| Salted MD5 | `{CRYPT}$1$GfHYI7OE$vlXqMZSyJOSPXAmbXHq250` (prefix optional) |
| PLAIN-MD5 | `0d2bf3c712402f428d48fed691850bfc` (unprefixed hex) |
| Plain text | the password itself |

and states that for MySQL/PostgreSQL the prefixes used are `{PLAIN-MD5}`,
`{PLAIN}`, `{SSHA}`, `{SSHA512}`, `{CRYPT}`.
— https://docs.iredmail.org/password.hashes.html

**2. The prefix is Dovecot's convention, not iRedMail's.** Dovecot allows any
password to be prefixed with `{SCHEME}` to override the configured default, and
supports optional encoding suffixes such as `.b64` / `.hex`
(e.g. `{PLAIN.b64}…`).
— https://doc.dovecot.org/2.3/configuration_manual/authentication/password_schemes/

**3. Unprefixed values are not invalid — they are interpreted with a fallback.**
iRedMail's shipped Dovecot SQL config sets:

```
default_pass_scheme = CRYPT
```

— https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot-sql.conf

So a legacy row with a bare `$1$…` crypt string authenticates fine on the mail
server. Any Mailward verifier that requires a prefix will reject accounts the
mail server accepts.

**4. Defaults by era — this is the legacy set.**
- MySQL/PostgreSQL, iRedMail 0.9.0 and later: **SSHA512**
- MySQL/PostgreSQL, earlier than 0.9.0: **Salted MD5**
- BSD platforms: **BCRYPT**
— https://docs.iredmail.org/password.hashes.html

  The page also says MD5 is not safe and should not be used, and that BCRYPT is
  unavailable on Linux because glibc does not support it.
— https://docs.iredmail.org/password.hashes.html

**5. Current install-time default for SQL backends is SSHA512:**

```
# Default password scheme for SQL backends.
# LDAP backend will use SSHA instead (defined in dialog/config_via_dialog.sh)
# for easier third-party application integrations.
export DEFAULT_PASSWORD_SCHEME='SSHA512'
```

— https://github.com/iredmail/iRedMail/blob/master/conf/global

  iRedMail's user-creation tool documents the same default in a variable named
  `PASSWORD_SCHEME`, noting BCRYPT is recommended on FreeBSD/OpenBSD.
— https://docs.iredmail.org/sql.create.mail.user.html

**6. The reference way to produce a hash is `doveadm pw`, and the output is
stored verbatim, prefix included:**

```
$ doveadm pw -s 'ssha512' -p '123456'
{SSHA512}jOcGSlKEz95VeuLGecbL0MwJKy0yWY9foj6UlUVfZ2O2SNkEExU3n42YJLXDbLnu3ghnIRBkwDMsM31q7OI0jY5B/5E=

$ doveadm pw -s 'blf-crypt' -p '123'
{BLF-CRYPT}$2a$05$9CTW6FZtjHeK6W.2YMmzOeAj2YFvDpP4JEH0uH/YLQI81jPWDtzQW
```

followed by `UPDATE mailbox SET password='{SSHA512}…' WHERE username='…';`
— https://docs.iredmail.org/reset.user.password.html

  Note the discrepancy that matters: `doveadm` emits `{BLF-CRYPT}` while
  iRedMail's own scheme table documents bcrypt hashes as `{CRYPT}$2a$…`
  (https://docs.iredmail.org/password.hashes.html). **Both forms can therefore
  exist in the column**, and a verifier must accept both.

**7. `doveadm pw` can enumerate and self-test.** `-l` lists all supported
schemes and exits; `-t hash` verifies a hash internally and prints the result.
Available schemes (BLF-CRYPT, SHA256-CRYPT, SHA512-CRYPT) depend on the host's
libc.
— https://doc.dovecot.org/2.4.0/core/man/doveadm-pw.1.html

**8. There is no "configured scheme" column in `vmail`.** The `mailbox` table
stores `password VARCHAR(255)` and nothing describing how it was produced
(https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql).
`DEFAULT_PASSWORD_SCHEME` is an installer shell variable
(https://github.com/iredmail/iRedMail/blob/master/conf/global), and the
generating scheme used by iRedAdmin lives in that panel's own Python settings
file, not in the database
(https://docs.iredmail.org/password.hashes.html). Both are outside the `vmail`
connection Mailward has (`docs/01-architecture.md` §3).

### Hypothesis (unconfirmed)

**Verification set** — Mailward's custom hasher must verify, at minimum:

| Stored form | Notes |
|---|---|
| `{SSHA512}…` | current default, base64 of hash+salt |
| `{SSHA}…` | older default and LDAP-era installs |
| `{CRYPT}$2a$…` / `{CRYPT}$2b$…` / `{CRYPT}$2y$…` | bcrypt, iRedMail-style prefix |
| `{BLF-CRYPT}$2…$…` | bcrypt, doveadm-style prefix — same hash, different label |
| `{CRYPT}$1$…` | salted MD5, pre-0.9.0 installs |
| `{CRYPT}$5$…`, `{CRYPT}$6$…` | SHA256/SHA512-crypt, if any tooling produced them |
| `{PLAIN-MD5}…` | unsalted hex MD5 |
| `{PLAIN}…` | plain text |
| bare `$1$…` / `$2a$…` / `$6$…` | **no prefix** — falls back to `default_pass_scheme = CRYPT` |
| bare 32-char hex | almost certainly PLAIN-MD5 |

PHP's `password_verify` and `crypt()` cover the `$2*$`, `$1$`, `$5$`, `$6$`
families natively; SSHA/SSHA512 are base64(hash ‖ salt) and are verified by
re-hashing with the extracted salt. None of this requires reading iRedMail code.

**Generation** — the scheme Mailward *writes* should be an explicit,
operator-visible Mailward configuration value, defaulting to SSHA512, and
**not** guessed silently. Rationale: no runtime source of truth exists for it
inside `vmail` (established fact 8), and guessing wrong produces accounts that
cannot authenticate — a failure that surfaces to end users, not to the admin who
made the change.

The default may be *proposed* by inspecting what is already in the column:

```sql
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(password, '}', 1), '{', -1) AS scheme, COUNT(*)
FROM vmail.mailbox
WHERE password LIKE '{%}%'
GROUP BY scheme ORDER BY COUNT(*) DESC;   -- MySQL
```

The modal prefix among existing accounts is the best available proxy for "what
this server generates", and it is derived from the connection Mailward already
has. Unprefixed rows must be counted separately and reported, since they are the
ones the fallback silently rescues today.

Mailward should additionally **refuse to start, or warn loudly, if the
configured generation scheme is one it cannot itself verify** — writing a hash
the panel cannot read back is a self-inflicted lockout.

Open sub-question, unsourced: whether `passwordlastchange` must be updated on
every password write (`docs/02-domain.md` §1.2 lists it among the sentinel-date
columns), and whether any iRedMail component enforces expiry from it.

### How to confirm

**1. Inventory the real column** on the disposable VM, and on any real install
being onboarded:

```sql
-- Prefix distribution, prefixed rows
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(password, '}', 1), '{', -1) AS scheme, COUNT(*)
FROM vmail.mailbox WHERE password LIKE '{%}%' GROUP BY scheme ORDER BY 2 DESC;

-- Rows with no prefix at all — the fallback cases
SELECT username, LEFT(password, 8), LENGTH(password)
FROM vmail.mailbox WHERE password NOT LIKE '{%}%';
```

PostgreSQL equivalent: `split_part(split_part(password, '}', 1), '{', 2)`.

**2. Read the server's actual fallback**, rather than assuming the sample file
matches the install:

```bash
grep -r 'default_pass_scheme' /etc/dovecot/
sudo doveadm config 2>/dev/null | grep -i 'pass_scheme\|passdb\|driver = sql'
cat /etc/iredmail-release
```

Confirms established fact 3 if the value is `CRYPT`. If it is anything else,
the unprefixed-row interpretation changes and the verifier must follow.

**3. Enumerate what this host's Dovecot can actually do**, which bounds what
Mailward may generate:

```bash
doveadm pw -l
```

**4. Generate one hash per scheme and pin them as test fixtures** — this is
exactly the corpus `docs/01-architecture.md` §8 requires ("hashes generated by a
real iRedMail install, not by our own code"):

```bash
for s in ssha512 ssha sha512-crypt md5-crypt plain-md5 plain blf-crypt; do
  printf '%s\t' "$s"; doveadm pw -s "$s" -p 'correct horse battery staple' 2>&1
done | tee /tmp/mailward-hash-fixtures.tsv
```

Then round-trip each one through `doveadm pw -t`:

```bash
doveadm pw -t '{SSHA512}…' -p 'correct horse battery staple'
```

Every fixture must verify. Mailward's hasher is then tested against this exact
file; any fixture it cannot verify is a bug in Mailward, not in the fixture.

**5. Prove the loop end to end.** Write a hash Mailward generated into a test
account and authenticate against the real server, not against our own verifier:

```bash
doveadm auth test probe@example.com 'the password'
```

This is the only test that proves Mailward's generated scheme is one the mail
server accepts. It must be run for each scheme Mailward is willing to generate.

**6. Check the storage limit.** `password` is `VARCHAR(255)`
(https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql).
Confirm the chosen scheme's output fits with margin — silent truncation on a
non-strict MySQL would produce an unauthenticatable account with no error.

### What it blocks

- The custom hasher in `app/Support/PasswordScheme/`
  (`docs/01-architecture.md` §7) — both halves of it.
- The custom user provider and therefore **login itself**
  (`docs/01-architecture.md` §5). Nothing in the panel works before this.
- The change-password Action on mailboxes (`docs/02-domain.md` §4 BR).
- The password-scheme test fixtures (`docs/01-architecture.md` §8).
- Whether Mailward needs a configuration setting for the generation scheme at
  all, and whether it needs to shell out to `doveadm` (which would contradict
  the "plain SQL, unprivileged" property in `docs/01-architecture.md` §6 if it
  became a runtime dependency rather than a build-time fixture generator).

---

## Still unknown

Nothing sourced was found for any of the following. They must be answered by
observation on a real install, or by a deliberate Mailward decision.

**OQ-02**

- Whether any iRedMail component **reads** `domain_admins.active` or its
  `expired` date, or whether only the row's existence matters.
- Whether iRedMail ever writes a sentinel other than `ALL`, in any supported
  version, and whether case is ever anything but uppercase.
- What the installer writes for `created` on the `postmaster@` `domain_admins`
  row — a real timestamp or the `1970-01-01 01:01:01` sentinel.
- Whether removing a global admin's `ALL` row while leaving `isglobaladmin = 1`
  is a state iRedMail tolerates.

**OQ-03**

- **Whether Dovecot auto-creates the maildir on first delivery.** The single
  most consequential unknown in this document: it decides whether mailbox
  creation is plain SQL or needs a privileged helper.
- The exact hash-level rule: number of levels, one character per level vs
  cumulative prefixes, and whether the domain participates. Public
  documentation shows one example, `u/s/e` for `username`, which does not
  discriminate between the candidate rules.
- The rule for local parts shorter than the number of levels.
- The rule for non-alphanumeric or non-ASCII leading characters in the local
  part.
- Whether the value carries a trailing slash.
- Whether older supported iRedMail versions stored an **absolute** path in
  `mailbox.maildir` — which would make the column's meaning version-dependent
  and force a compatibility check. Scoped by the supported range in
  `docs/00-overview.md` §8.
- How the single documented `STORAGE_BASE_DIRECTORY` (`/var/vmail/vmail1`) is
  split between the `storagebasedirectory` and `storagenode` columns.
- Whether multiple storage nodes are meant to be selectable per account by an
  admin panel, and what iRedMail expects when they are.

**OQ-04**

- Whether any currently supported iRedMail version generates anything other
  than SSHA512 on Linux SQL backends by default.
- Whether `{CRYPT}` and `{BLF-CRYPT}` bcrypt rows genuinely coexist on real
  installs, or whether that is only a difference between two documentation
  pages.
- Whether Dovecot's encoding suffixes (`.b64`, `.hex`) ever appear in
  iRedMail-managed rows in practice.
- Whether `passwordlastchange` is read by anything, and what iRedMail writes to
  it on a password change.
- Whether any supported iRedMail version stores an unprefixed hash *by default*
  today, rather than only as a legacy artefact.

**Cross-cutting**

- The supported version range is upstream of all three, and is now decided —
  1.7.3 and later, validated against 1.8.4 (`docs/00-overview.md` §8). Every
  answer above is version-scoped, and the verification procedures should be run
  on each version in that range, recording `/etc/iredmail-release`
  alongside every observation
  (https://docs.iredmail.org/upgrade.iredmail.1.7.2-1.7.3.html). Release notes
  and per-version upgrade guides are indexed at
  https://docs.iredmail.org/iredmail.releases.html.

---

## Sources

- https://docs.iredmail.org/promote.user.to.be.global.admin.html
- https://docs.iredmail.org/password.hashes.html
- https://docs.iredmail.org/reset.user.password.html
- https://docs.iredmail.org/sql.create.mail.user.html
- https://docs.iredmail.org/sql.bulk.create.mail.users.html
- https://docs.iredmail.org/iredadmin-pro.customize.maildir.path.html
- https://docs.iredmail.org/migrate.to.new.iredmail.server.html
- https://docs.iredmail.org/change.mailbox.format.html
- https://docs.iredmail.org/upgrade.iredmail.1.7.2-1.7.3.html
- https://docs.iredmail.org/iredmail.releases.html
- https://github.com/iredmail/iRedMail/blob/master/conf/global
- https://github.com/iredmail/iRedMail/blob/master/samples/iredmail/iredmail.mysql
- https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot-sql.conf
- https://raw.githubusercontent.com/iredmail/iRedMail/master/samples/dovecot/dovecot.conf
- https://forum.iredmail.org/post91217.html
- https://doc.dovecot.org/2.3/configuration_manual/authentication/password_schemes/
- https://doc.dovecot.org/2.4.0/core/man/doveadm-pw.1.html

Only documentation, schema, sample configuration files and public forum posts
were read. No iRedAdmin-Pro source was consulted, and no code from iRedMail or
iRedAdmin was copied into this document or into Mailward
(`docs/decisions/0006-mit-license.md`).
