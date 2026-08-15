# Architecture

Status: draft

---

## 1. Stack

Resolved on 2026-08-14. Verify with the commands in `standards/versions.md`
before bootstrapping; do not trust these numbers later.

| Layer | Choice | Version at resolution |
|---|---|---|
| Language | PHP | 8.5.9 |
| Framework | Laravel | 13.25.0 |
| Frontend | Inertia + Vue 3 + TypeScript | — |
| Build | Vite | — |
| Package managers | Composer 2.9.5 / pnpm | — |
| Node | 24.14.0 (LTS line) | — |
| Application database | PostgreSQL or MySQL | — |

Frontend rationale: `docs/decisions/0004-inertia-vue.md`.

### Local development

Per `playbooks/local-environment.md`:

- Project lives at `/var/www/dev/mailward`, served at `https://mailward.dev.test`
  by the existing wildcard vhost. **No vhost is written for this project.**
- The application database is created natively. No Docker.
- The iRedMail side is a **disposable VM** running a real iRedMail install.
  There is no way to develop this honestly against a mocked schema.

---

## 2. The Central Boundary

Mailward talks to two different things, and confusing them is the main
architectural risk:

```
┌──────────────────────────┐
│  Mailward application DB │  owned by Mailward
│  migrations, full DDL    │  sessions, jobs, audit, settings, 2FA
└──────────────────────────┘
              ▲
              │
      ┌───────┴────────┐
      │    Mailward    │
      └───────┬────────┘
              │  DML only — SELECT / INSERT / UPDATE / DELETE
              ▼
┌──────────────────────────┐
│  iRedMail databases      │  owned by iRedMail
│  vmail, iredapd, amavisd │  schema created by the installer
└──────────────────────────┘
```

**Mailward never issues DDL against an iRedMail database.** No migrations, no
`ALTER TABLE`, no new columns, no new indexes. See
`docs/decisions/0002-separate-application-database.md`.

When a feature needs a field iRedMail does not have, the field goes in
Mailward's own database, keyed by the account's email address. Never as a new
column on `mailbox`.

---

## 3. Database Connections

| Connection | Database | Access | Migrations |
|---|---|---|---|
| `mailward` (default) | Mailward's own | full | yes |
| `vmail` | iRedMail accounts | DML only | **never** |
| `iredapd` | iRedMail policy daemon | DML only | **never** |
| `amavisd` | Amavis | read only | **never** |

Only `vmail` is used in v1; the others are declared when their features land.

Because the default connection is Mailward's own database, `migrate:fresh` and
`migrate:rollback` are physically incapable of touching mail data.

Models bound to an iRedMail database declare `$connection` explicitly and are
never referenced by a migration.

### Enforcement, not convention

The database user Mailward uses for `vmail` is granted only
`SELECT, INSERT, UPDATE, DELETE`. No `CREATE`, no `ALTER`, no `DROP`.
A mistake then fails at the database, not at our discipline.

### Consequences to design around

- **No foreign keys** from Mailward tables to `vmail` tables. References are
  plain `varchar` email addresses. Orphans are possible and must be handled
  explicitly on delete.
- **No cross-database joins.** MySQL would allow it; PostgreSQL would not.
  Treat it as impossible in both. Filter and paginate on one side, correlate in
  PHP.
- **No distributed transactions.** An operation spanning both sides must be
  ordered deliberately and be idempotent on retry. The `vmail` write is the
  last commit.

---

## 4. Supported Backends

SQL only — MySQL/MariaDB and PostgreSQL. The OpenLDAP backend is out of scope
(`docs/decisions/0001-sql-backend-only.md`).

The MySQL and PostgreSQL schema files shipped by iRedMail are **not identical**
in column types and defaults. The matrix is in
`docs/reference/schema-type-matrix.md`, and the test suite runs against both
drivers — `composer test`.

**PostgreSQL is the default and the first deployment target.** Both backends
are equally supported, and the choice of default is not neutral: of the
divergences in the matrix, nearly all of them pass silently on MySQL and fail
loudly on PostgreSQL. MySQL's `utf8mb4_general_ci` makes address lookups
case-insensitive by accident, it accepts a PHP boolean where PostgreSQL
rejects it against `INT2`, and it fills `used_quota.domain` from a trigger that
exists nowhere else.

Defaulting to PostgreSQL therefore means a driver mistake surfaces during
development instead of hiding until someone installs on the strict backend.
The MySQL cell of the matrix is still run on every change; it is second in the
sequence, not absent.

---

## 5. Authentication

Mailward has no `users` table and does not use Laravel's default auth stack.

- Identity comes from `vmail.mailbox`.
- The password is the account's real mail password. Changing it in Mailward
  changes IMAP, SMTP and webmail access.
- A **custom user provider** resolves the `Mailbox` model on the `vmail`
  connection.
- A **custom hasher** handles iRedMail's password formats. It must *verify*
  every scheme that may exist on legacy accounts (`{SSHA512}`, `{BCRYPT}`, …)
  while *generating* only the scheme the server is configured for.
- Sessions, "remember me", 2FA secrets and preferences live in Mailward's
  database, keyed by email address.

### Authenticating is not authorisation

Every mail user on the server has a valid password. Login must additionally
require `isadmin` or `isglobaladmin`; otherwise the entire user population can
enter the panel.

The login form is effectively a password oracle against real mail accounts.
It requires aggressive rate limiting and an identical response for
"unknown address" and "wrong password".

---

## 6. Privileged Operations

PHP-FPM runs unprivileged. Most of the product is plain SQL and needs nothing
more.

The operations that touch the system are handled as follows:

- **Mailbox deletion** — Mailward inserts into `vmail.deleted_mailboxes` and
  iRedMail's own cron job removes the files. No shell access required. This is
  the preferred pattern: let iRedMail's existing machinery do privileged work.
- **Anything genuinely requiring root** — a narrow, explicitly whitelisted
  helper invoked through `sudo`, with a fixed command and arguments validated
  against an allowlist. Never a shell string built from request data.

---

## 7. Application Structure

Standard Laravel per `standards/laravel/architecture.md`:

- Controllers are thin, return `Inertia::render()`, delegate to Actions
- Business logic in single-purpose Actions
- Input via FormRequest, output via Resource
- Eloquent as the data layer, no repositories
- Domain states as PHP enums, never as database enums

Mail-specific additions:

- `app/Models/Mail/` — models bound to iRedMail connections, read-mostly,
  no migrations, `$timestamps = false`, string primary keys
- `app/Support/PasswordScheme/` — hash generation and verification
- Every mail model applies an authorisation scope by default
  (`docs/policies/authorization.md`)

---

## 8. Testing

- Feature tests assert Inertia responses
- The full suite runs against **both** MySQL and PostgreSQL — the driver
  differences are real and are exactly what would otherwise ship broken
- Password scheme tests validate against hashes generated by a real iRedMail
  install, not by our own code
- Acceptance criteria in feature docs map one-to-one to tests

---

## 9. Licence

MIT — `docs/decisions/0006-mit-license.md`.

Mailward contains no code from iRedMail or iRedAdmin. It reads a schema, which
is not a derivative work of the software that creates it.
