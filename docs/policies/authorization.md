# Authorization

Status: draft

Applies to every feature. A feature document may narrow this policy, never
contradict it.

---

## 1. Roles

Two roles, both sourced from iRedMail (`docs/decisions/0003-reuse-iredmail-admin-model.md`):

| Role | Source | Scope |
|---|---|---|
| Global admin | `mailbox.isglobaladmin = 1` | every domain |
| Domain admin | rows in `domain_admins` | only the listed domains |

There is no third role in v1. Mail users cannot log in
(`docs/decisions/0003`, §Consequences).

## 2. The Scope Rule

Every domain-owned resource — mailbox, alias, forwarding, alias domain, domain
admin assignment — resolves its domain and is visible only if:

```
actor is global admin  OR  resource.domain ∈ actor's administered domains
```

This is the only authorization concept in the product. Anything more elaborate
is out of scope until a feature document justifies it.

## 3. Enforcement Is in the Query

**The scope filter belongs in the query, not only in the Policy.**

A domain admin listing mailboxes must never load every mailbox and filter
afterwards. With thousands of accounts that is both slow and one pagination bug
away from leaking another domain's data.

- Mail models apply the domain scope by default, as a global scope
- Policies are the second barrier, on individual records
- Removing the scope is explicit, permitted only for a global admin, and
  visible at the call site

## 4. The Server Decides

Permissions are passed to the frontend as props, for UX only — to show or hide
controls. A hidden button is not a security boundary. Every request is
authorized again on the server, regardless of what the UI offered.

## 5. Rules That Prevent Lockout

- **BR-A01** — The last global admin cannot be demoted, deactivated or deleted.
  The check runs inside the same transaction as the change.
- **BR-A02** — An administrator cannot revoke their own global admin flag.
- **BR-A03** — Deleting a domain removes its `domain_admins` rows. An
  administrator left with no domains and no global flag can still log in and
  sees an empty state, not an error.
- **BR-A04** — A recovery command exists outside the web interface:
  `php artisan mailward:promote <address> --global`, runnable by anyone with
  shell access to the server. This is the documented escape hatch.

## 6. Login Gate

Authentication and authorization are separate steps, and conflating them is a
critical bug:

1. Credentials are verified against `vmail.mailbox` — every mail account on the
   server passes this step
2. Access is granted **only** if `isadmin` or `isglobaladmin` is set
3. `active = 0` or an `expired` date in the past denies access at both steps

Failures at step 2 are logged as authorization failures, not authentication
failures, and return the same generic message as a wrong password.

## 7. Audit

Every write is recorded in `audit_log` with the acting address, the action, the
target, and the before/after values.

"Every write" is narrowed to a definite list in `docs/features/audit-log.md`
BR-17 and BR-18, which this policy permits a feature document to do. Read
literally it would cover `sessions`, `cache` and `jobs`, and a log containing
those is a log nobody reads. What is recorded: writes to `vmail`, writes to
Mailward's own `settings`, successful sign-ins, and deliberate refusals.

Authorization failures are recorded too —
a domain admin repeatedly probing another domain's resources is exactly what
the log exists to show.
