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

The range is set once the first install is validated. See Open Questions.

## 9. Open Questions

Research status is tracked in `docs/reference/open-questions-research.md` and
`docs/reference/current-iredmail-behaviour.md`.

**`docs/reference/observed-install.md` is different in kind**: it records the
actual server this project exists to administer, measured rather than sourced.
It settles the maildir layout and the password scheme inventory. It also names
what Mailward replaces: an existing Laravel panel writing into `vmail`, which
is discarded at the migration — only the mail infrastructure data travels.

- **OQ-01** — Which iRedMail versions form the initial supported range?
  *Proposal on the table:* 1.7.3 (April 2025) and later, because 1.7.3 is the
  last release to add columns to `mailbox` and `deleted_mailboxes`, and §2 of
  the architecture forbids Mailward from adding them itself. Current stable is
  1.8.4. Awaiting a decision.
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

- **OQ-05** — What unit is `mailbox.quota`? `02-domain.md` §4 says bytes;
  iRedMail's own Dovecot query multiplies it by 1048576. Both readings produce
  a plausible number on screen, and one of them is wrong by a factor of a
  million.

The nine feature documents in `docs/features/` raise roughly sixty further
questions. They are deduplicated and ranked in
`docs/reference/decisions-needed.md`, which is the working list.
