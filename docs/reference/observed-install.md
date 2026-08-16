# The install Mailward will administer

Status: observed — 2026-08-15

Taken from a read-only pre-migration survey of `mail.sulonline.net`, the server
this project exists to administer. Everything here is **measured**, not
sourced from documentation, which makes it the first answer to several
questions the specification could only guess at.

Two servers matter, and they are not the same:

| | Today | After migration |
|---|---|---|
| OS | FreeBSD 11.2 | Debian 13 |
| iRedMail | 0.9.5-1 | current |
| Backend | PostgreSQL 9.5.13 | PostgreSQL |
| Dovecot | 2.3.2.1 | **2.4** — a different configuration generation |

Mailward targets the second. The first matters because its data moves there
unchanged.

---

## 1. Size

| | |
|---|---|
| Mailboxes | 1,151 |
| Domains | 6 |
| Standalone aliases | 317 |
| Forwardings | 411 |
| Global admins | 3 |
| Domain admins | 12 |
| Disabled mailboxes | 65 |
| Rows in `deleted_mailboxes` | 31 |
| Mail | 657 GB across 7.5 M messages |

The largest domain holds 744 mailboxes; the smallest, 5. One account alone
holds 78 GB in 1.67 M messages.

**Consequences for Mailward.** A listing of 744 rows is the normal case, not
the outlier — pagination and the query-level domain scope are load-bearing
rather than defensive. And `deleted_mailboxes` already has rows, so the table
is live on this install, not merely present.

---

## 2. `maildir` — E2, answered

Observed layout:

```
/var/vmail/vmail1/<domain>/<a>/<b>/<c>/<user>-<date>/Maildir/
└──┬───┘ └──┬──┘ └──────────────────┬─────────────────────┘
   │        │                       └─ mailbox.maildir
   │        └─ mailbox.storagenode
   └─ mailbox.storagebasedirectory
```

This **confirms the derivation Mailward already implements**: the absolute
path written to `deleted_mailboxes.maildir` is the concatenation of the three
columns, and `mailbox.maildir` alone is the relative tail
(`RecordDeletedMailboxes::absoluteMaildir()`).

It also settles the shape: three hash levels of one character each, taken from
the local part, plus a date suffix on the account directory. The survey notes
the convention is unchanged in the current iRedMail, which is what makes the
mail files movable without rewriting the column.

Still unconfirmed: the exact rule for a local part shorter than three
characters, and what the date suffix is derived from. Neither blocks anything —
nothing parses this column, and Dovecot creates the directory itself.

---

## 3. Password schemes — E3, answered

Measured on the FreeBSD 11.2 server. Corrected twice, and each correction
removed a threat rather than adding one — earlier readings reported traditional
DES and then md5crypt. **Neither exists. Every account is bcrypt.**

| Stored scheme | Cost | Accounts |
|---|---|---|
| bcrypt `{CRYPT}` | 10 | 332 |
| bcrypt `{CRYPT}` | 12 | 81 |
| bcrypt `{BLF-CRYPT}` | 12 | 67 |
| bcrypt `{BLF-CRYPT}` | **5** | **83** |
| | | **563** |

**The migration risk on passwords is gone.** Dovecot 2.4 disables the MD5
family and DES by default, and this server has neither. Every hash here is
bcrypt, which 2.4 accepts at any cost. Nothing needs rehashing to survive the
move, and the asymmetry recorded as C3 — Mailward admitting an administrator
whose mail client can no longer authenticate — cannot occur on this data.

What remains is one finding, and it is a weakness rather than a break.

**83 accounts at cost 5.** Dovecot accepts them, nothing fails, nobody is told.
Cost 5 is thirty-two iterations where 10 is a thousand and 12 is four thousand.
The label hides it completely: `{BLF-CRYPT}` at cost 5 is spelled exactly like
`{BLF-CRYPT}` at cost 12, so anything reading the scheme name alone — including
Mailward, until this was found — calls the two identical.

`SchemeRegistry::weakness()` now reads the work factor instead of trusting the
label. It reports and never rewrites: the column is the account's real mail
password, and changing it changes IMAP, SMTP and webmail at once.

**Both labels are bcrypt.** `{CRYPT}$2a$` and `{BLF-CRYPT}` are the same
algorithm under two names — iRedMail's own tables use the first, `doveadm`
emits the second, and this server carries both. Mailward resolves either.

**Configuration note for this deployment.** `MAILWARD_PASSWORD_SCHEME` defaults
to `SSHA512`. On a server where every existing account is bcrypt, setting it to
`BLF-CRYPT` keeps new accounts consistent with the ones already there. Either
works — Dovecot reads the prefix per row — but a single scheme across the
estate is one less thing to reason about later.

**One figure to reconcile:** the survey counted 1,151 mailboxes and this
inventory totals 563. The gap is not explained, and it matters: an account with
no usable password cannot sign in anywhere. The 65 disabled and 31 deleted
accounts do not account for 588. Worth
`SELECT COUNT(*) FROM mailbox WHERE password = ''`.

---

## 4. The schema is not stock iRedMail

The `vmail` database carries tables and columns that iRedMail did not create:

| Found | What it is |
|---|---|
| `migrations`, `users`, `roles`, `permissions`, `permission_role`, `permission_user`, `role_user`, `password_resets` | A Laravel application using `spatie/laravel-permission`, writing into `vmail` directly. Its code is not on the mail server |
| `mailbox.id_loja`, `mailbox.id_contrato` | Custom columns tying a mailbox to a store and a contract — an ERP or CRM link |

**Resolved, 2026-08-15: that application is what Mailward replaces.** It is
discarded entirely at the migration, and only the mail infrastructure data
travels. Its administrative surface does not.

So the two writers never coexist, `01-architecture.md` §2 holds as written, and
Mailward targets the stock iRedMail schema — which is what it was built
against. The migration imports the iRedMail tables into a clean install;
`id_loja`, `id_contrato` and the Laravel tables simply do not follow. Nothing
here asks Mailward to drop them, which it could not do anyway: it issues no DDL
against `vmail` (`0002-separate-application-database.md`).

One thing worth noticing, because it closes a loop. The discarded application
holds its roles in `roles`, `permissions` and `role_user` — the
`spatie/laravel-permission` shape. Mailward deliberately does not: roles come
from `mailbox.isglobaladmin` and `domain_admins`
(`0003-reuse-iredmail-admin-model.md`), so there is one source of truth for who
administers what instead of a copy that can drift from the mail server. The
system being replaced is a working example of the drift that decision avoids.

**Residual, and small:** if the migration ever imports into an existing
database rather than a clean one, those columns come with it. Reading stays
safe — every model declares an explicit `$fillable` and no write goes through
`SELECT *` — and a `NOT NULL` custom column without a default would fail loudly
on the first insert rather than silently.

---

## 5. What this does not change

The survey's other findings — an expired certificate, a lapsed DNSSEC
signature, a full swap partition, a queue backed up behind a typo — are the
mail server's operational state. Mailward administers accounts; it does not
install, configure or monitor the stack (`00-overview.md` §2). They are
recorded here only because they describe the machine this software will run
against, and because a server in that condition is not a fair test of anything
Mailward does.

One is worth carrying into the roadmap rather than the spec: the survey found
**65 disabled mailboxes and 31 deleted ones**, and a queue where deferrals
outnumbered deliveries. A panel that surfaces those without being asked is more
useful than one that waits to be queried.
