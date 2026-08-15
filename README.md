# Mailward

A web administration panel for an **existing** iRedMail server.

> **Status: early development.** The specification is being written before the
> code, and no feature is implemented yet. See `docs/`.

iRedMail installs and runs the mail stack — Postfix, Dovecot, Amavis, iRedAPD.
It ships an open source admin panel that covers a fraction of what the stack can
do; the rest sits behind a commercial product. Mailward administers the same
server through the databases iRedMail already created, and aims to cover the
management surface an administrator actually needs, without a licence.

## Not affiliated with iRedMail

**Mailward is an independent project. It is not affiliated with, endorsed by, or
connected to iRedMail or its authors in any way.**

The names "iRedMail" and "iRedAdmin" appear here only descriptively, to say what
Mailward works with. No branding, logos, screenshots or documentation text are
reused. Mailward is written from scratch against the database schema published
by the iRedMail installer, which is GPL and public; no iRedAdmin-Pro source code
was consulted at any point, and none may be consulted by contributors. See
`docs/decisions/0006-mit-license.md`.

## Requirements

- An existing iRedMail installation on a **SQL backend** — MySQL/MariaDB or
  PostgreSQL
- PHP 8.5, Node 24 LTS
- A database of its own, separate from iRedMail's

### Installations Mailward cannot administer

**iRedMail installed with the OpenLDAP backend is not supported and will not
be.** The LDAP backend is a different data model, not a driver difference. If
your install uses LDAP, Mailward has nothing to offer you — this is stated here
so it is discovered before installing rather than after
(`docs/decisions/0001-sql-backend-only.md`).

## What Mailward is not

- **Not a mail server.** It configures nothing at install time and replaces no
  daemon. Without iRedMail it has nothing to do
- **Not an installer.** iRedMail installs itself; Mailward arrives afterwards
- **Not multi-tenant, not SaaS.** One organisation, one installation, many mail
  domains. No billing, no signup, no tenant isolation
- **Not a webmail client.** Reading mail is Roundcube's or SOGo's job

## How it treats your data

Mailward keeps its own database for its own tables — sessions, audit log, 2FA,
settings. Against iRedMail's databases it issues **DML only**: `SELECT`,
`INSERT`, `UPDATE`, `DELETE`. No migrations, no `ALTER TABLE`, no added columns
or indexes, ever. The database user it connects with should be granted DML
privileges only, so that boundary is enforced by the server rather than by
trust. See `docs/decisions/0002-separate-application-database.md`.

Administrators are ordinary mail accounts flagged in iRedMail, not a separate
set of panel users. A fresh iRedMail install already has a global admin, so
there is no setup wizard and no seeding.

## Documentation

`docs/` is the source of truth for this project and is written before the code.

| Document | Contents |
|---|---|
| `docs/00-overview.md` | Product, scope, glossary |
| `docs/01-architecture.md` | Stack, boundaries, integrations |
| `docs/02-domain.md` | The iRedMail schema as Mailward reads it |
| `docs/policies/` | Cross-cutting rules |
| `docs/features/` | One document per feature |
| `docs/decisions/` | Architectural decision records |
| `docs/reference/` | Derived material, such as the driver type matrix |

## Licence

MIT — see `LICENSE`.

Mailward contains no code from iRedMail or iRedAdmin. It reads a schema, which
is not a derivative work of the software that creates it.
