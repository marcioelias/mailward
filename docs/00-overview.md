# Mailward — Overview

Status: draft

---

## 1. What Mailward Is

A web administration panel for an **existing** iRedMail server.

iRedMail installs and runs the mail stack — Postfix, Dovecot, Amavis, iRedAPD.
It ships an open source admin panel (iRedAdmin) that covers only a fraction of
what the stack can do; the rest is behind the commercial iRedAdmin-Pro.

Mailward administers the same server through the databases iRedMail already
created, and aims to cover the management surface an administrator actually
needs, without a licence.

## 2. What Mailward Is Not

- **Not a mail server.** It configures nothing at install time and replaces no
  daemon. If iRedMail is not installed, Mailward has nothing to do.
- **Not an installer.** iRedMail installs itself; Mailward arrives afterwards.
- **Not multi-tenant, not SaaS.** One organisation, one installation, many mail
  domains. There is no billing, no signup, no tenant isolation.
- **Not a webmail client.** Reading mail is Roundcube's or SOGo's job.
- **Not affiliated with iRedMail.** Independent project, no endorsement.

## 3. Deployment Shape

A single installation, on or beside the mail server, run by one organisation
that administers several mail domains. Administrators are staff, not customers.

This shape is a deliberate constraint. Anything that only makes sense for a
hosting provider selling mailboxes to third parties is out of scope, and its
absence is a feature.

## 4. Actors

| Actor | Source of truth | Access |
|---|---|---|
| Global admin | `mailbox.isglobaladmin = 1` | every domain, every account |
| Domain admin | `domain_admins` rows | only the domains assigned to them |
| Mail user | `mailbox` | none in v1 |

Administrators are ordinary mail accounts with a flag. There is no separate
panel account — see `docs/decisions/0003-reuse-iredmail-admin-model.md`.

Consequence: a fresh iRedMail install already has a global admin (the
`postmaster@` account created during installation). Mailward requires no setup
wizard and no seeding. It is usable on first boot.

## 5. Scope

### v1

- Domains: list, create, edit, disable, delete; per-domain limits
- Alias domains
- Mailboxes: full CRUD, quota, password, enabled services, active/inactive
- Per-user aliases and forwardings
- Standalone alias accounts and their members
- Domain admins: promote, demote, assign domains
- Quota usage, per account and per domain
- Last login per account
- Dashboard: account counts, quota totals, domains at their limit
- Audit log of every write Mailward performs

### Later

- Self-service for mail users (own password, own forwardings, vacation)
- Greylisting, throttling and white/blacklists (`iredapd`)
- Amavis quarantine and spam policy
- Mailing lists (mlmmj)
- BCC maps, sender-dependent relayhost, shared folders
- Log viewer, Postfix queue, service status
- REST API

### Out of scope

- OpenLDAP backend (`docs/decisions/0001-sql-backend-only.md`)
- Installing or upgrading iRedMail itself
- DNS, TLS certificates, system provisioning
- Anything requiring an iRedMail version outside the supported range

## 6. Glossary

| Term | Meaning |
|---|---|
| `vmail` | The iRedMail database holding domains, mailboxes and aliases |
| Mailbox | A real mail account. Row in `vmail.mailbox`, primary key is the address |
| Alias | A standalone address that only redirects. Row in `vmail.alias` |
| Forwarding | A row in `vmail.forwardings`; serves four distinct purposes, see `02-domain.md` |
| Alias domain | A domain whose mail is delivered to another domain |
| Maildir | Absolute filesystem path of an account's mail storage |
| Domain admin | Mail account allowed to administer specific domains |
| Global admin | Mail account allowed to administer everything |
| iRedAPD | iRedMail's Postfix policy daemon: greylisting, throttling, white/blacklists |
| mlmmj | The mailing list manager iRedMail integrates |

## 7. Relationship to iRedMail

Mailward is written from scratch against the database schema published by the
iRedMail installer, which is GPL and public. **No iRedAdmin-Pro source code was
consulted at any point**, and none may be consulted by contributors.

The names "iRedMail" and "iRedAdmin" are used only descriptively, to say what
Mailward works with. No branding, logos or documentation text are reused.

## 8. Supported iRedMail Versions

The schema belongs to iRedMail and changes across releases. Mailward declares a
supported version range, detects the installed version at runtime, and refuses
to operate outside it rather than corrupting data.

**The range is iRedMail 1.7.3 (April 2025) and later, on MySQL/MariaDB or
PostgreSQL. Validated against 1.8.4.** An install below the floor is **refused
with a clear message** naming the detected shape and the required minimum,
rather than starting and half-working (`docs/reference/decisions-needed.md` Q23,
answered 2026-08-15, option A).

Why 1.7.3 is the floor, argued from schema shape rather than from an upstream
policy — iRedMail publishes no support-lifetime statement to anchor a range to,
so this is Mailward's own choice
(`docs/reference/current-iredmail-behaviour.md` §4):

- **1.7.3 is the last release that added columns to `vmail.mailbox`** —
  `first_name`, `last_name`, `mobile`, `telephone`, `birthday`, `recovery_email`
  — and to `vmail.deleted_mailboxes` — `bytes`, `messages`. Below it those
  columns do not exist, so a feature that reads them fails with a SQL error
  instead of degrading, and `docs/01-architecture.md` §2 forbids Mailward from
  adding them itself. The floor therefore has to be the release that ships them.
- **1.7.2 performed the `utf8mb4` conversion** on MySQL, which the case handling
  of `docs/decisions/0005-lowercase-canonical-addresses.md` depends on. A floor
  at 1.7.3 satisfies it by being later.
- **1.8.0 is inside the range, not above it.** It is the first release whose
  Dovecot configuration is generation 2.4, and the two generations differ on what
  an unprefixed password hash means. A range that stopped below 1.8.0 would
  exclude precisely the installs where that difference matters.

Two consequences, both deliberate:

- **Detection is inferred from schema shape.** `/etc/iredmail-release` is a file,
  not a column, and Mailward has SQL connections only
  (`docs/01-architecture.md` §3) — no version string is readable over them. The
  version floor is therefore detected by the presence of the columns 1.7.3
  introduced, together with the nine expected tables, and the refusal path is a
  bootstrap concern rather than a login one.
- **The real test matrix is two SQL drivers × two Dovecot configuration
  generations**, not two drivers alone as `docs/01-architecture.md` §8 currently
  says. Password verification is the axis that differs, and a second disposable
  VM on a 2.4 distribution is needed before the hasher can be called done.

Lowering the floor later is a deliberate decision with a stated cost — a second
column set in `docs/reference/schema-type-matrix.md` and permanently conditional
reads for eight columns — and never something to discover in production.

## 9. Open Questions

Research status is tracked in `docs/reference/open-questions-research.md` and
`docs/reference/current-iredmail-behaviour.md`.

**`docs/reference/observed-install.md` is different in kind**: it records the
actual server this project exists to administer, measured rather than sourced.
It settles the maildir layout and the password scheme inventory. It also names
what Mailward replaces: an existing Laravel panel writing into `vmail`, which
is discarded at the migration — only the mail infrastructure data travels.

- **OQ-02** — How is a global admin represented in `domain_admins`? Is a row
  written with a sentinel domain, or does `isglobaladmin` alone suffice?
  *Sourced answer:* both are written, the sentinel being the literal `ALL`.
  Every join from `domain_admins` to `domain` must exclude it.
- **OQ-03** — What algorithm generates `mailbox.maildir`?
  *Largely dissolved.* The path is configuration, not a constant
  (`decisions/0007`), and the branch that would have hurt is closed: Dovecot
  creates the mail directory itself, because iRedMail always sets the location
  explicitly. Creating a mailbox is plain SQL and needs no privileged helper,
  which confirms `01-architecture.md` §6 rather than contradicting it.
- **OQ-04** — Which password schemes must be supported for verification, and
  how is the server's configured scheme detected?
  *Partly answered, and messier than it looked.* A current iRedMail ships two
  different Dovecot configurations — 2.3 and 2.4 — and they do not agree on
  what an unprefixed hash means, nor on which legacy schemes still work. The
  real test axis is the Dovecot generation, not the iRedMail version.

### Newly opened by that research

- **OQ-05 — answered 2026-08-15.** `mailbox.quota` and `domain.maxquota` are in
  **mebibytes**, confirmed three ways against a live install
  (`docs/reference/observed-install.md` §4). The same reading corrected Q15:
  `maxquota` is an aggregate pool for the domain, not a per-mailbox ceiling.

### Opened by the decisions of 2026-08-15

> **OQ-06 — Is the health check a v1 feature, and does it need a specification of
> its own?**
>
> Four separate decisions now assign work to "the health check", and **no feature
> document owns it**: there is no `docs/features/health-check.md`, no route, no
> contract, no actor table and no acceptance criteria for it anywhere. Each
> owning document states what the check must surface, and none of them states
> what the check *is*. What it now carries:
>
> 1. **Unverifiable password schemes** — scan the `{SCHEME}` prefixes in
>    `mailbox.password` and report every row this instance cannot verify,
>    grouped by scheme, plus the banner shown to signed-in administrators
>    (`docs/features/authentication.md` BR-20;
>    `docs/reference/decisions-needed.md` Q12).
> 2. **The iRedMail version floor** — report an install below the supported range
>    of §8, which is otherwise only enforced at bootstrap
>    (`docs/reference/decisions-needed.md` Q23).
> 3. **Global-admin drift** — report `mailbox.isglobaladmin` and the
>    `domain_admins` `'ALL'` sentinel disagreeing, repairing nothing
>    (`docs/features/domain-admins.md` BR-19;
>    `docs/reference/decisions-needed.md` Q24).
> 4. **Mixed-case addresses** — the pre-existing scan for rows that are dead
>    accounts on PostgreSQL
>    (`docs/decisions/0005-lowercase-canonical-addresses.md`, Correction —
>    2026-08-15, and its Consequences).
>
> All four are the same read-only pass over `vmail`, and all four are findings an
> operator acts on rather than states Mailward changes. What is undecided is
> whether v1 ships it at all, and if so: where it lives (a panel screen, an
> artisan command, or both), who may run it (global admin only, by analogy with
> the audit log), whether it runs on a schedule or only on demand, how the banner
> of item 1 is computed without scanning `mailbox` on every request, and whether
> a finding may ever offer a repair action — item 3 says explicitly that it must
> not repair during a read, while `0005` contemplates a check that "offers to
> normalise" mixed-case rows, so the two are not yet consistent with each other.
> **Deferring it has a stated cost:** three of the four decisions above are
> answered on the assumption that this surface exists, so without it an
> unverifiable scheme, a drifted global admin and a mixed-case row are each
> known to Mailward and reported to nobody.

The nine feature documents in `docs/features/` raise roughly sixty further
questions. They were deduplicated and ranked in
`docs/reference/decisions-needed.md`, which is now a closed record: every
question on it has been answered, and what remains there is empirical.
