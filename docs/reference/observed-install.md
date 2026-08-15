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

Measured across 1,151 accounts:

| Stored scheme | Accounts | Verifiable by Mailward | Accepted by Dovecot 2.4 |
|---|---|---|---|
| `{CRYPT}$2y$` bcrypt | 429 | yes | yes |
| `{CRYPT}$1$` md5crypt | 191 | yes | **disabled by default** |
| `{CRYPT}$2a$` bcrypt | 153 | yes | yes |
| `{BLF-CRYPT}$2y$` / `$2a$` | 90 | yes | yes |
| `{CRYPT}` traditional DES, 13 chars, no `$` | ~288 | yes | **disabled by default** |

Every one of these verifies through `SchemeRegistry` today — `CryptScheme`
delegates to PHP's `crypt()`, which reads the algorithm from the value's own
magic and handles bare DES.

**That is the problem, not the reassurance.** The destination runs Dovecot 2.4,
which disables the MD5 family and DES by default. So for **roughly 479 accounts
— 42% of the server** — Mailward would accept a password that IMAP refuses.
The administrator signs into the panel and their mail client fails, which is
the exact asymmetry recorded as C3 and the reason `authentication.md` BR-10
was written the way it was.

This is no longer a hypothetical to be confirmed on a VM. It is 479 real
accounts, and it makes a scheme-inventory health check the most valuable thing
Mailward could offer this deployment: *these accounts will stop authenticating
when you move.*

---

## 4. The schema is not stock iRedMail

The `vmail` database carries tables and columns that iRedMail did not create:

| Found | What it is |
|---|---|
| `migrations`, `users`, `roles`, `permissions`, `permission_role`, `permission_user`, `role_user`, `password_resets` | A Laravel application using `spatie/laravel-permission`, writing into `vmail` directly. Its code is not on the mail server |
| `mailbox.id_loja`, `mailbox.id_contrato` | Custom columns tying a mailbox to a store and a contract — an ERP or CRM link |

**This is the largest open risk to Mailward, and it has two halves.**

**Reading is safe.** Mailward's models declare an explicit `$fillable` and
never `SELECT *` into a write, so unknown columns are ignored rather than
mishandled.

**Writing may not be.** If `id_loja` or `id_contrato` is `NOT NULL` without a
default, every mailbox Mailward creates fails at the database. If they are
nullable, Mailward creates accounts that the other system cannot see — which is
worse, because nothing errors.

And there is a second system writing the same rows. `01-architecture.md` §2
assumes iRedMail is the only other writer. It is not.

**Open, and blocking mailbox creation on this install:**

- Are `id_loja` and `id_contrato` nullable, and does the other application
  require them to be populated?
- Does that application continue to run after the migration, or does Mailward
  replace it? If they coexist, they are two writers with no shared lock, and
  the "no distributed transactions" rule in `01-architecture.md` §3 acquires a
  second meaning nobody designed for.

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
