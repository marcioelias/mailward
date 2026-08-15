# 0003 — Administrators come from iRedMail, not from a Mailward table

Status: accepted — 2026-08-14

## Context

Mailward needs to know who may log in and which domains they may administer.
Two options:

1. Reuse iRedMail's model — `mailbox.isadmin` / `mailbox.isglobaladmin` plus
   the `domain_admins` table
2. Keep a Mailward `users` table, with its own passwords and its own
   domain assignments

## Decision

Reuse iRedMail's model. Administrators are mail accounts with a flag.

Mailward's database stores only what iRedMail has no place for: 2FA secrets,
panel preferences, last panel login, audit log — all keyed by email address.

## Consequences

**Gained**

- One source of truth for who administers what. Nothing can drift
- Zero-configuration first run: a fresh iRedMail install already has a global
  admin (`postmaster@`), so Mailward is usable immediately, with no setup
  wizard and no seeding
- Drop-in replacement: Mailward can run alongside iRedAdmin during evaluation,
  with no migration and no commitment

**Paid**

- Laravel's default authentication stack is unusable. Mailward needs a custom
  user provider over the `Mailbox` model on the `vmail` connection, and a
  custom hasher for iRedMail's password formats — verifying every scheme that
  may exist on legacy accounts, generating only the configured one. This is the
  single place where the project fights the framework
- The panel password is the mail password. Changing it in Mailward changes
  IMAP, SMTP and webmail access. Correct behaviour, but it must be stated in
  the interface
- **Every mail account on the server has a valid password.** Authentication
  alone cannot gate the panel; the `isadmin` / `isglobaladmin` check is
  mandatory and is a security boundary, not a convenience
  (`docs/policies/authorization.md` §6)
- The login form is a password oracle against real mail accounts, and needs
  stricter rate limiting than an ordinary application login

## Note

The legacy `admin` table still exists in the iRedMail schema and is not used by
current iRedMail versions. Mailward ignores it. Documented in `02-domain.md` §8
so it is not rediscovered and wired up by mistake.
</content>
