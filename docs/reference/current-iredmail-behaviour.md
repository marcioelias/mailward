# Current iRedMail behaviour — mail store creation, password schemes, address case

Status: research — 2026-08-15

Scope: a **current** iRedMail release on a SQL backend (MySQL/MariaDB or
PostgreSQL). Current stable at the time of writing is **1.8.4**, released
**22 July 2026** (§4).

Method, and its limits:

- Primary sources are Dovecot's own documentation (`doc.dovecot.org`),
  iRedMail's documentation (`docs.iredmail.org`) and the sample configuration,
  installer variable files and public tooling in the iRedMail repository.
- Files were read at git tag `1.8.4`. Every file cited below is byte-identical
  between tag `1.8.4` and `master`, except `conf/global`, which differs only in
  `PROG_VERSION` and one unrelated line.
- The iRedMail forum is used **only as corroboration**, never as the basis of a
  conclusion.
- No iRedAdmin-Pro source was consulted, and no code was copied
  (`docs/decisions/0006-mit-license.md`). What is quoted below is individual
  configuration setting values and column names — facts about a data format,
  not expression.
- **Nothing here has been verified against a running install.** Every section
  ends with what remains unsourced and what the probe on the disposable VM must
  confirm.

One structural fact governs all three questions and is established here once:

**A current iRedMail ships two different Dovecot configurations.** The
installer selects Dovecot 2.4 on Debian 13 (trixie) and Ubuntu 26.04
(resolute), and Dovecot 2.3 everywhere else
(`conf/dovecot`, `DOVECOT_VERSION`;
https://github.com/iredmail/iRedMail/blob/1.8.4/conf/dovecot). The two paths use
different sample files — `samples/dovecot/dovecot.conf` plus
`samples/dovecot/dovecot-sql.conf` for 2.3, and
`samples/dovecot/dovecot-2.4-mariadb.conf` / `-pgsql.conf` for 2.4
(`functions/dovecot.sh`;
https://github.com/iredmail/iRedMail/blob/1.8.4/functions/dovecot.sh). Dovecot
2.4 support arrived in iRedMail **1.8.0** (13 Apr 2026), the release that added
Debian 13 and Ubuntu 26.04 (`ChangeLog`;
https://github.com/iredmail/iRedMail/blob/1.8.4/ChangeLog).

They do not behave identically. Q2 turns on the difference.

---

## Q1 — Is a filesystem step required to create a mailbox?

**Question** — `docs/01-architecture.md` §6 asserts that Mailward can create
accounts with plain SQL as an unprivileged PHP-FPM process. That holds only if
something else creates the user's mail directory. Does it?

### What is established

**1. The mail path is set explicitly and per-user, from SQL.** It is never left
to autodetection.

On the 2.3 path, `dovecot.conf` sets
`mail_location = maildir:%Lh/Maildir/:INDEX=%Lh/Maildir/`, and the userdb query
overrides it per user, returning `home` as
`LOWER(CONCAT(storagebasedirectory, '/', storagenode, '/', maildir))` and `mail`
as `CONCAT(mailboxformat, ':~/', mailboxfolder)`.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot.conf
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-sql.conf

On the 2.4 path the same values arrive through the renamed settings:
`mail_driver = %{userdb:mail_driver | default("maildir") | lower}` and
`mail_path = %{userdb:mail_path | default("~/Maildir")}`, with the userdb query
returning `home`, `mail_driver` and `mail_path` from the same four columns.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-2.4-mariadb.conf

This matters because Dovecot's auto-creation is conditional on the location
being set (fact 2).

**2. Dovecot creates the mail storage path itself, including missing parent
directories.** Dovecot's current documentation, under the heading *Mail Storage
Autocreation*:

> If `mail_path` is set, the path is automatically created if any directories
> are missing.

and it describes this as "the easiest way to ensure a freshly created user is
correctly set up for access via Dovecot".
— https://doc.dovecot.org/main/core/config/mail_location.html

The 2.3 documentation says the same thing under *Mailbox Autocreation*:

> Autocreation is only triggered if `mail_location` is correctly set.

with the explicit converse for the autodetection case: "when autodetection is
active, Dovecot will not attempt to create a mail folder". iRedMail always sets
the location, so it is never in the autodetection case.
— https://doc.dovecot.org/2.3/configuration_manual/mail_location/

Both pages attach one condition: Dovecot must have write access to create the
directories. iRedMail satisfies this by running the mail processes as the
`vmail` user (`mail_uid` / `mail_gid`, fact 5).

**3. `lda_mailbox_autocreate` is about mailbox FOLDERS, not the maildir root.**
This is the trap in the question, and the documentation settles it. The setting
is defined as:

> Should LDA create a nonexistent mailbox automatically when attempting to save
> a mail message? — default `no`

— https://doc.dovecot.org/2.3/settings/core/ (unchanged name and wording in 2.4:
https://doc.dovecot.org/main/core/summaries/settings.html)

"Mailbox" there is the IMAP folder — the destination selected with
`dovecot-lda -m <mailbox>`, whose documentation reads: "Destination mailbox
(default is INBOX). If the mailbox doesn't exist, it will not be created (unless
the `lda_mailbox_autocreate` setting is set to yes)".
— https://doc.dovecot.org/2.3/configuration_manual/protocols/lda/

iRedMail sets `lda_mailbox_autocreate = yes` and
`lda_mailbox_autosubscribe = yes` inside `protocol lda` on both the 2.3 and 2.4
paths. That governs Sieve `fileinto` targets such as `Junk` — it is not what
creates the maildir on disk, and it is not the setting the architecture claim
depends on.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot.conf

**4. Delivery path: a current iRedMail ships dovecot-lda, not LMTP — and it does
not change the answer.** `virtual_transport` is set from `TRANSPORT`, which
defaults to `DOVECOT_LDA_DELIVER`, whose value is `dovecot`; the `dovecot`
service in `master.cf` is a Postfix pipe to the `deliver` binary running as
`vmail:vmail`.
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/postfix
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/dovecot
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/postfix/main.cf.dovecot
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/postfix/master.cf

The LMTP service is nevertheless configured and the protocol enabled
(`DOVECOT_PROTOCOLS="pop3 imap sieve lmtp"`), so an administrator can switch
`virtual_transport` to `lmtp:unix:private/dovecot-lmtp` without further work.
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/dovecot

The choice is irrelevant to this question: auto-creation is a property of the
mail storage layer, triggered by namespace initialisation, which happens in any
mail process — `imap`, `lmtp` or `lda` alike (fact 2). Neither LMTP nor LDA has
its own root-creation setting.

**5. Whatever creates the directory cannot be Mailward.** The installer sets the
mailbox storage tree to `vmail:vmail`, mode `0700`
(`functions/system_accounts.sh`). An unprivileged PHP-FPM worker cannot traverse
it, let alone create in it. Dovecot can, because `mail_uid` / `mail_gid` make
its mail processes run as `vmail`.
— https://github.com/iredmail/iRedMail/blob/1.8.4/functions/system_accounts.sh
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot.conf

So the question is not "should Mailward do the filesystem step" but "does
anything have to". If a step were required, it would require `sudo` — the
expensive branch of `01-architecture.md` §6.

**6. iRedMail's own supported way to create a user performs no filesystem
operation.** `tools/create_mail_user_SQL.sh` — the tool
`docs.iredmail.org/sql.create.mail.user.html` documents — computes the hashed
maildir string, generates the password hash, and emits exactly two `INSERT`
statements, into `mailbox` and `forwardings`. The file contains no `mkdir`, no
`chown`, no `chmod`, and no shell-out other than `doveadm pw`.
— https://github.com/iredmail/iRedMail/blob/1.8.4/tools/create_mail_user_SQL.sh
— https://docs.iredmail.org/sql.create.mail.user.html

This is the strongest single piece of evidence. iRedMail's own answer to "how do
I create a user" is two rows of SQL.

**7. The one place the installer does `mkdir` a maildir, and why it is not a
counter-example.** `functions/system_accounts.sh` creates
`…/Maildir/new` for the first `postmaster` account. The reason is stated in the
file and confirmed by `functions/cleanup.sh`: the installer writes the
installation-details messages (`details.eml`, `links.eml`, `mua.eml`) **directly
into `Maildir/new/` as files**, bypassing SMTP entirely. A directory you are
about to write files into by hand must exist. That is a requirement of the file
drop, not of Dovecot.
— https://github.com/iredmail/iRedMail/blob/1.8.4/functions/system_accounts.sh
— https://github.com/iredmail/iRedMail/blob/1.8.4/functions/cleanup.sh

Note also that the postmaster maildir is hashed **without** the timestamp
suffix, unlike every subsequent account — relevant to OQ-03, not to this
question.

**8. Corroboration (secondary, forum).** The iRedMail developer, answering
precisely this question: "Maildir will be created while: - When user first
login, either via POP3 or IMAP - Email received."
— https://forum.iredmail.org/topic5123-iredmail-support-automatically-create-folder.html

The post is from 2013 and carries no weight on its own. It agrees with facts 2
and 6.

### Conclusion

**Creating a row in `mailbox` is sufficient.** No filesystem step is required,
and `docs/01-architecture.md` §6 stands as written.

Two consequences that are not the same as "it works", and that Mailward must
design around:

- The directory **does not exist** between account creation and the first
  delivery or first IMAP/POP3 login. Anything that reads the filesystem to
  confirm an account — a health check, a quota display, a "mailbox size" column
  — must treat absence as normal for a new account, not as an error.
- Pre-seeding a maildir (migration, restoring mail into a new account before the
  user ever logs in) is a genuinely privileged operation and is a *different*
  feature. It does not belong to account creation and must not be smuggled into
  it.

### What remains unsourced

- Dovecot's wording is "the path is automatically created if any directories are
  missing". It does not spell out that this includes creating the user's **home**
  directory when `mail_path` is `~/Maildir`, nor how many levels of a 4-deep
  hashed path it will create in one go. The literal reading covers both; it has
  not been observed.
- Whether the created directories land with the mode iRedMail expects (`0700`,
  `vmail:vmail`) when Dovecot rather than the installer creates them.
- Whether first **login** creates the root as reliably as first **delivery** on
  a current release. Only the 2013 forum post claims the login case.

All three are answered by one probe on the disposable VM: insert a `mailbox` row
by hand, confirm nothing exists on disk, deliver one message, then re-check the
path, ownership and mode; then repeat with an IMAP login instead of a delivery.

---

## Q2 — What password scheme does a current iRedMail generate, and can rows exist
## without a `{SCHEME}` prefix?

**Question** — Prior research (`open-questions-research.md`, OQ-04) recorded
`default_pass_scheme = CRYPT`, which would make unprefixed rows valid. Does that
still hold, what does a current release generate, and must Mailward's verifier
tolerate a bare hash?

### What is established

**1. The generating scheme at install time is SSHA512 on Linux, BCRYPT on the
BSDs.** `DEFAULT_PASSWORD_SCHEME='SSHA512'` for SQL backends, overridden to
`'BCRYPT'` in the FreeBSD and OpenBSD branches of the same file. Unchanged from
what OQ-04 recorded.
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/global

**2. Everything iRedMail writes is produced by `doveadm pw` and therefore carries
a `{SCHEME}` prefix.** The shared helper `generate_password_hash` runs
`doveadm pw -s <scheme> -p <password>` and stores the output verbatim; the
installer uses it for the postmaster hash, and
`tools/create_mail_user_SQL.sh` carries an identical copy.
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/core
— https://github.com/iredmail/iRedMail/blob/1.8.4/functions/backend.sh
— https://github.com/iredmail/iRedMail/blob/1.8.4/tools/create_mail_user_SQL.sh

Dovecot: "All generated password hashes have a `{scheme}` prefix".
— https://doc.dovecot.org/main/core/config/auth/schemes.html

So on a current release, a row written by iRedMail **always** has a prefix.

**3. bcrypt is labelled `{BLF-CRYPT}`, not `{CRYPT}$2a$`, by anything current.**
`generate_password_hash` rewrites the scheme name `BCRYPT` to `BLF-CRYPT` before
calling `doveadm pw`, in both the installer helper and the SQL tool. A BSD
install therefore stores `{BLF-CRYPT}$2…$…`.
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/core
— https://github.com/iredmail/iRedMail/blob/1.8.4/tools/create_mail_user_SQL.sh

The `{CRYPT}$2a$…` form documented on
https://docs.iredmail.org/password.hashes.html is nevertheless valid and
verifiable, because Dovecot's `CRYPT` is "an umbrella term for all password
schemes libc's `crypt()` can verify", and that list includes bcrypt `$2b$`.
— https://doc.dovecot.org/main/core/config/auth/schemes.html

**Both labels can appear and both must be accepted.** They denote the same hash.

**4. The prefix overrides a per-passdb default; without a prefix, the default
decides.** "The password scheme can be overridden for each password by prefixing
it with `{SCHEME}`" and "All passdbs have a default scheme for passwords stored
without the `{scheme}` prefix".
— https://doc.dovecot.org/2.3/configuration_manual/authentication/password_schemes/
— https://doc.dovecot.org/main/core/config/auth/schemes.html

This is the whole purpose of the setting, and it confirms the semantics OQ-04
assumed.

**5. On the Dovecot 2.3 path, that default is still `CRYPT`.**
`samples/dovecot/dovecot-sql.conf` sets `default_pass_scheme = CRYPT`, unchanged
at tag 1.8.4.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-sql.conf

`CRYPT` means libc's `crypt()`, which identifies the algorithm from the `$id$`
magic inside the hash itself — `$1$` md5crypt, `$5$` sha256crypt, `$6$`
sha512crypt, `$2b$` bcrypt, `$y$` yescrypt, and so on. That is how a bare hash
is dispatched: **the hash body, not the row, says which algorithm it is.**
— https://doc.dovecot.org/main/core/config/auth/schemes.html

**6. On the Dovecot 2.4 path, iRedMail sets no default scheme at all — and
Dovecot's own default is `PLAIN` on both platforms that use that path.** This is
the finding that changes the practical answer.

`samples/dovecot/dovecot-2.4-mariadb.conf` and `-pgsql.conf` contain no
occurrence of the string `scheme`; the `passdb sql` block carries only a query.
Nor does `functions/dovecot.sh` inject one at install time.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-2.4-mariadb.conf
— https://github.com/iredmail/iRedMail/blob/1.8.4/functions/dovecot.sh

In Dovecot 2.4 the setting is renamed `passdb_default_password_scheme`, and its
default changed mid-series:

| Dovecot | `passdb_default_password_scheme` default | Source |
|---|---|---|
| 2.4.1 | `PLAIN` | https://doc.dovecot.org/2.4.1/core/summaries/settings.html |
| 2.4.3 | `CRYPT` ("Changed: 2.4.3 Changed from PLAIN to CRYPT") | https://doc.dovecot.org/2.4.3/core/summaries/settings.html |

And the two distributions that select the 2.4 path both ship a version below
2.4.3:

- Debian 13 (trixie): `dovecot-core 1:2.4.1+dfsg1-…` —
  https://packages.debian.org/trixie/dovecot-core
- Ubuntu 26.04 (resolute): `dovecot-core 1:2.4.2+dfsg1-…` —
  https://packages.ubuntu.com/resolute/dovecot-core

An unprefixed hash on such an install is compared as **cleartext** and fails.
It is not "verified as CRYPT"; it is not verified at all.

**7. Dovecot 2.4 additionally disables weak schemes by default.** "Some password
schemes are disabled by default due to being considered weak. This includes MD
based (except DIGEST-MD5 and CRAM-MD5), LANMAN, NTLM and a few others… You can
enable these with `auth_allow_weak_schemes = yes`", and `MD5-CRYPT` and
`DES-CRYPT` are individually marked "Changed: 2.4.0 Disabled by default".
— https://doc.dovecot.org/main/core/config/auth/schemes.html

iRedMail's 2.4 samples do not set `auth_allow_weak_schemes`. So legacy
`{CRYPT}$1$…` and `{PLAIN-MD5}…` rows — the pre-0.9.0 defaults — stop
authenticating on a Debian 13 / Ubuntu 26.04 install even though they carry a
correct prefix.

### Conclusion

**A current iRedMail generates a prefixed value: `{SSHA512}…` on Linux,
`{BLF-CRYPT}…` on FreeBSD/OpenBSD. Mailward must still tolerate unprefixed
values on read, and must never write one.**

How the verifier knows which algorithm to try, when there is no prefix: from the
hash body. A leading `$id$` is a libc `crypt()` string and self-identifies
(`$1$`, `$2*$`, `$5$`, `$6$`, `$y$`); PHP's `crypt()` / `password_verify()`
handle those directly. A bare 32-character hex string is *probably* PLAIN-MD5,
but that is an inference from shape, not a documented rule — see below.

Two things follow that the hasher design must reflect:

- **Unprefixed is not universally valid on a current install.** It authenticates
  on the Dovecot 2.3 configuration and fails on the 2.4 one. So Mailward
  verifying an unprefixed row successfully does **not** mean the mail server
  will. If Mailward's login succeeds where IMAP fails, Mailward has become a
  liar about the state of the account.
- Consequently an unprefixed row, and any MD5-family row, is worth **reporting**
  as a health-check finding — "this account cannot log in to IMAP on a Dovecot
  2.4 server" — rather than silently accepting. This is the same class of
  finding as the mixed-case rows in `0005`.

`docs/decisions/0007` is unaffected: the scheme remains configuration, and the
option set now also has to record which label bcrypt is written under.

### What remains unsourced

- Whether iRedMail regards the missing `passdb_default_password_scheme` in its
  2.4 samples as intentional or as an oversight. Nothing in the ChangeLog or the
  documentation addresses it. Do not repeat this as an iRedMail claim; it is an
  observation about the shipped file plus Dovecot's documented default.
- That a bare 32-hex value is PLAIN-MD5. iRedMail's hash page shows PLAIN-MD5
  stored unprefixed as hex, but nothing states that an unprefixed hex string is
  *always* PLAIN-MD5 — and under `default_pass_scheme = CRYPT` such a value is
  handed to `crypt()`, not to an MD5 comparison. Mailward should not guess this
  one silently.
- Whether `doveadm pw -s SSHA512` output on a current build is byte-compatible
  with what a PHP re-implementation produces. `01-architecture.md` §8 already
  requires testing against hashes from a real install; that requirement stands
  and this research does not discharge it.

---

## Q3 — Does a current iRedMail normalise address case at authentication?

**Question** — `docs/decisions/0005-lowercase-canonical-addresses.md` asserts
that iRedMail's shipped Dovecot config does not set `auth_username_format`, "so
the username reaches the SQL query exactly as the client typed it". Verify
against a current release.

### What is established

**1. The first half of the assertion is correct: iRedMail sets
`auth_username_format` nowhere.** The string does not occur in
`samples/dovecot/dovecot.conf`, `samples/dovecot/dovecot-sql.conf`,
`samples/dovecot/dovecot-2.4-mariadb.conf` or `-pgsql.conf`, and
`functions/dovecot.sh` does not inject it.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot.conf
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-2.4-mariadb.conf

**2. The second half is wrong, because Dovecot's default is to lowercase.** The
setting is not unset-meaning-passthrough; it has a lowercasing default, and has
had one across both configuration generations:

| Dovecot | `auth_username_format` default | Source |
|---|---|---|
| 2.3 | `%Lu` | https://doc.dovecot.org/2.3/settings/core/ |
| 2.4 | `%{user \| lower}` | https://doc.dovecot.org/2.4.1/core/summaries/settings.html |

2.3 describes it as "Formatting applied to username before querying the auth
database"; 2.4 is more explicit: "When this setting is used globally, it changes
the username, including `%{user}` variable, for all passdb and userdb lookups."
— https://doc.dovecot.org/main/core/summaries/settings.html

The username that reaches `mailbox.username='%u'` in the 2.3 `password_query` is
therefore already lowercased. It is not what the client typed.

**3. On the 2.4 path iRedMail lowercases a second time, explicitly, inside the
SQL.** The `passdb sql` and `userdb sql` queries are written with
`mailbox.username='%{user | lower}'`, plus
`mailbox.domain='%{user | domain | lower}'` and `LOWER(…)` around the home path.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/dovecot/dovecot-2.4-mariadb.conf

There is no configuration in which the lookup key is mixed case.

**4. Nothing in the schema enforces lowercase, on either driver.**
`mailbox.username` is `VARCHAR(255)` in both schema files; the PostgreSQL schema
uses no `citext` and no `CHECK` constraint. The MySQL schema declares
`utf8mb4_general_ci`, which makes the comparison case-insensitive by accident,
exactly as `0005` says.
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/iredmail/iredmail.pgsql
— https://github.com/iredmail/iRedMail/blob/1.8.4/samples/iredmail/iredmail.mysql

**5. The current installer and its tooling do still write only lowercase.**
`tools/create_mail_user_SQL.sh` folds its input address to lower case before
building either the row or the maildir; `hash_maildir` in `conf/core` folds the
username again; the installer folds the first domain
(`dialog/virtual_domain_config.sh`) and the admin local part is the constant
`postmaster` (`conf/global`).
— https://github.com/iredmail/iRedMail/blob/1.8.4/tools/create_mail_user_SQL.sh
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/core
— https://github.com/iredmail/iRedMail/blob/1.8.4/conf/global

That guarantee covers only what iRedMail itself writes. Any other panel, script
or migration can write mixed case, and the database will accept it.

### Conclusion

**`0005`'s decision is right; its stated reason is wrong, and the real reason is
stronger.**

Correct chain of causation:

- Dovecot lowercases the username before the passdb and userdb lookups, by
  default, and iRedMail does not override it. On the 2.4 path iRedMail also
  lowercases inside the SQL.
- Therefore the lookup key is **always** lower case.
- Therefore a mixed-case row is found on MySQL (case-insensitive collation) and
  **never** found on PostgreSQL — regardless of what the user types.

The practical difference matters. Under `0005`'s stated premise, a PostgreSQL
user with a mixed-case row could log in by typing the exact casing; the defect
would be a usability wart. Under the actual behaviour there is no such
workaround: the account cannot authenticate at all, and the userdb lookup fails
too, so it has no home and cannot receive mail. A mixed-case row on PostgreSQL
is a **dead account**.

This raises the priority of the health check `0005` describes as "worth
building", and it means Mailward's own normalisation at the request boundary is
not merely defensive — writing a mixed-case row on PostgreSQL would create an
account that can never be used.

`0005`'s Context section should be corrected: the Dovecot bullet currently
states the opposite of the documented default.

### What remains unsourced

- Whether Dovecot's `auth_username_format` default was ever something other than
  a lowercasing format in a version an older iRedMail might have shipped. Only
  2.3 and 2.4 were checked; both lowercase. If it is ever asserted that some
  historical install passed case through, that needs its own source.
- What iRedAdmin (the shipped open-source panel) writes. It was deliberately not
  consulted. It is safest to assume mixed-case rows can exist from *some*
  source, which is what the health check assumes anyway.

---

## 4. Current version, and the supported range

### Current stable

| | |
|---|---|
| Current stable release | **1.8.4** |
| Released | **22 July 2026** |
| Development line | `master` reports `PROG_VERSION='1.8.5'` |

— https://docs.iredmail.org/iredmail.releases.html
— https://github.com/iredmail/iRedMail/blob/master/conf/global

Recent releases, with the dates that matter to a range decision:

| Version | Date | Why it matters here |
|---|---|---|
| 1.8.4 | 22 Jul 2026 | current stable |
| 1.8.0 | 13 Apr 2026 | adds Debian 13 + Ubuntu 26.04, and with them the **Dovecot 2.4** configuration path |
| 1.7.4 | 3 Jun 2025 | — |
| 1.7.3 | 4 Apr 2025 | last release to add columns to `vmail.mailbox` and `vmail.deleted_mailboxes` |
| 1.7.2 | 24 Jan 2025 | converts `vmail` to `utf8mb4` / `utf8mb4_general_ci` on MySQL |
| 1.7.0 | 17 Jul 2024 | — |
| 0.9.7 | 2017 | split `alias` into `forwardings` + `alias_moderators` — the old hard floor |

— https://docs.iredmail.org/iredmail.releases.html
— https://github.com/iredmail/iRedMail/blob/1.8.4/ChangeLog
— https://github.com/iredmail/iRedMail/blob/1.8.4/update/1.7.3/vmail.mysql
— https://github.com/iredmail/iRedMail/blob/1.8.4/update/1.7.2/vmail.mysql

### Recommendation

**Supported range: iRedMail 1.7.3 (4 Apr 2025) and later, on MySQL/MariaDB or
PostgreSQL. Validated against 1.8.4.**

Reasoning, in the order the constraints bind:

1. **1.7.3 is the last release that changed the shape of `vmail.mailbox`.** It
   adds `first_name`, `last_name`, `mobile`, `telephone`, `birthday` and
   `recovery_email` to `mailbox`, and `bytes` / `messages` to
   `deleted_mailboxes`. Below 1.7.3 those columns do not exist, so any Mailward
   feature that reads them fails with a SQL error rather than degrading. Since
   `01-architecture.md` §2 forbids Mailward from issuing DDL, Mailward cannot
   add them — the floor has to be the release that ships them.
2. **1.7.2 is the utf8mb4 conversion.** Choosing a floor at or above it means
   the MySQL collation is a single known value (`utf8mb4_general_ci`) rather
   than a per-install unknown, which the case handling in `0005` depends on.
   1.7.3 satisfies this by being later.
3. **1.8.0 must be inside the range, not above it.** It is the first release
   whose Dovecot configuration is generation 2.4, and Q2 and Q3 show the two
   generations behave differently. A range that stopped below 1.8.0 would
   exclude precisely the installs where the password-scheme behaviour differs.
4. **0.9.7 is the older structural floor** — the `alias` → `forwardings` split.
   Supporting below it means a genuinely different data model, not a few missing
   columns. It is mentioned only to record that it is not being proposed.
5. **The floor is cheap to lower later and expensive to lower silently.** If a
   deployment on 1.7.0–1.7.2 is ever needed, the cost is a second column set in
   `docs/reference/schema-type-matrix.md` and conditional reads for the six
   columns above. That is a deliberate decision to take then, not an accident to
   discover in production.

Two consequences for the test matrix in `01-architecture.md` §8, which currently
names only the two SQL drivers:

- The relevant axis is **not** the iRedMail version but the **Dovecot
  configuration generation** — 2.3 on most distributions, 2.4 on Debian 13 and
  Ubuntu 26.04. Password verification behaviour differs between them (Q2).
- So the matrix is two SQL drivers × two Dovecot generations, and the disposable
  VM (`playbooks/local-environment.md`) can only cover one generation at a time.
  A second VM on Debian 13 is needed before the password hasher can be called
  done.

This recommendation was adopted on 2026-08-15 and is now declared in
`docs/00-overview.md` §8 (`docs/reference/decisions-needed.md` Q23, option A).
Suggested wording for the README:

> Works with iRedMail 1.7.3 and later on MySQL/MariaDB or PostgreSQL.
> Developed and tested against iRedMail 1.8.4.

### What remains unsourced

- Whether anything in `vmail` changed between 1.7.3 and 1.8.4 in a way the
  `update/` directory does not capture. The absence of an `update/1.8.x`
  directory is evidence of no schema change, not proof of it.
- iRedMail publishes no support-lifetime policy that could anchor "supported
  range" to anything official. The range above is Mailward's own choice,
  justified by schema shape, not by an upstream statement.
