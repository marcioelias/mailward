# 0002 — Separate application database, no DDL on iRedMail schemas

Status: accepted — 2026-08-14

## Context

Mailward's data naturally splits in two. The mail domain — domains, mailboxes,
aliases — already exists in iRedMail's `vmail` database and has exactly one
source of truth. Panel data — sessions, jobs, audit log, 2FA, settings — does
not exist anywhere and belongs to Mailward.

The tempting shortcut is to put everything in `vmail`: one connection, foreign
keys, joins, transactions.

## Decision

Mailward uses **its own database** for its own tables, and treats iRedMail's
databases as external systems it may read and write rows in, but never alter.

- `vmail`, `iredapd` and `amavisd` receive **DML only** — `SELECT`, `INSERT`,
  `UPDATE`, `DELETE`
- **No DDL, ever** — no migrations, no `ALTER TABLE`, no added columns or
  indexes on any iRedMail table
- The database user Mailward uses for those connections is granted DML
  privileges only, so this is enforced by the server rather than by discipline
- The Laravel default connection is Mailward's own database

## Consequences

**Gained**

- iRedMail upgrades run their own SQL against `vmail` without meeting our
  tables
- `migrate:fresh` and `migrate:rollback` cannot reach mail data
- Uninstalling Mailward is dropping one database
- A missing field is answered by a Mailward table keyed by email address, which
  keeps the boundary intact

**Paid**

- No foreign keys from Mailward tables to mail tables. References are address
  strings; orphan cleanup is explicit application logic
- No cross-database joins. MySQL would permit them, PostgreSQL would not, so
  they are treated as impossible on both. Correlation happens in PHP
- No transactions spanning both databases. Operations touching both are ordered
  deliberately, with the `vmail` write last, and are idempotent on retry
