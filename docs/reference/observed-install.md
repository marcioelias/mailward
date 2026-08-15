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

## 3. Password schemes — E3, answered, and it is worse than expected

Measured on the FreeBSD 11.2 server, corrected 2026-08-15 — this supersedes an
earlier count that reported traditional DES accounts. **There are none.**

| Stored scheme | Cost | Accounts | Verifiable by Mailward | Accepted by Dovecot 2.4 |
|---|---|---|---|---|
| bcrypt `{CRYPT}` | 10 | 353 | yes | yes |
| bcrypt `{CRYPT}` | 12 | 82 | yes | yes |
| bcrypt `{BLF-CRYPT}` | **5** | 84 | yes | yes |
| md5crypt `{CRYPT}$1$` | — | 130 | yes | **disabled by default** |
| | | **649** | | |

Two problems, and they are not the same problem.

**130 md5crypt accounts break on the move.** Dovecot 2.4 disables the MD5
family by default, and Debian 13 is the destination. Mailward verifies them, so
on that server it would admit an administrator whose mail client can no longer
authenticate. This is the operating system and mail server's to resolve before
the migration, not Mailward's — see below.

**84 bcrypt accounts at cost 5 do not break anything.** Dovecot accepts them
normally. They are simply weak: cost 5 is thirty-two iterations where cost 10
is a thousand and 12 is four thousand. Nothing fails, nobody is told, and the
label gives no hint — `{CRYPT}$2a$` at cost 5 is spelled exactly like
`{CRYPT}$2a$` at cost 12.

That last point was a gap in Mailward and is now closed:
`SchemeRegistry::weakness()` reads the work factor rather than trusting the
label, and reports md5crypt, cleartext, an unreadable scheme and an empty
password alongside it. It reports and never rewrites — the column is the
account's real mail password.

**One figure to reconcile:** the survey counted 1,151 mailboxes and this
inventory totals 649. The difference is not explained, and it matters: an
account with no usable password cannot sign in anywhere. Worth a count of
`mailbox` rows whose `password` is empty.

**Out of scope, decided 2026-08-15.** This is a property of the operating
system's crypt library and the mail server's configuration, not of Mailward,
and it is being resolved before the migration — the accounts are rehashed, or
Dovecot is configured to keep accepting the old schemes. Either way it is
settled on the mail server, where it belongs. Mailward installs nothing and
configures no daemon (`00-overview.md` §2).

What survives for Mailward is smaller and still true: the verifier must read
every scheme a legacy account may carry, which it does, and it must never be
the thing that quietly masks a mismatch. That is already how it behaves — a
scheme it cannot read raises rather than returning a denial
(`0007-configurable-maildir-and-password-scheme.md`).

Recorded here because the numbers are the clearest evidence yet for why
verification is exhaustive by design: on this server, a verifier that handled
only the current scheme would have locked out 42% of the accounts.

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
