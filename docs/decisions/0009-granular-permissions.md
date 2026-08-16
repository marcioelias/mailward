# 0009 — Granular permissions, in Mailward's own database

Status: accepted — 2026-08-16

## Context

`docs/policies/authorization.md` §2 has said, since the first commit, that two
roles are the only authorization concept in the product and anything more
elaborate is out of scope until a feature document justifies it. This record is
that justification.

The system Mailward replaces had granular permissions — `users`, `roles`,
`permissions`, `role_user` — and kept them **inside the `vmail` database**
(`docs/reference/observed-install.md` §5). That placement had two costs. An
iRedMail upgrade runs its own SQL against `vmail` and can take the tables with
it. And because those tables also decided *who was an administrator*, they were
a second source of truth that could disagree with the mail server about it.

The trade looked like durability against granularity. It is not: the two are
only in tension because the permissions were in the wrong database. Mailward
already has its own, and `0002-separate-application-database.md` exists exactly
so that a field iRedMail has no place for gets one without touching `vmail`.

## Decision

Granular permissions live in **Mailward's own database**, keyed by the
administrator's address, and they **narrow authority within a domain the actor
already administers. They never widen it.**

Authority is resolved in two layers, and they answer different questions:

| Layer | Source of truth | Answers |
|---|---|---|
| **Who administers what** | `vmail.mailbox.isglobaladmin`, `vmail.domain_admins` | which domains this actor may touch at all |
| **What they may do there** | Mailward's `administrator_abilities` | which operations, within those domains |

The outer layer is unchanged and remains iRedMail's
(`0003-reuse-iredmail-admin-model.md`), so Mailward and iRedAdmin cannot come
to disagree about who is an administrator. The inner layer is Mailward's alone
and is invisible to every other tool, which is correct: it describes a
restriction this panel imposes, not a fact about the mail server.

Three rules make the layering safe:

1. **A permission can only subtract.** No row in Mailward's database grants
   access to a domain `domain_admins` does not grant. The scope query runs
   first and unchanged; abilities are consulted afterwards, against what
   survived it.
2. **A global admin is not restricted.** `isglobaladmin` is the escape hatch
   the panel already depends on for recovery (BR-A04), and a permission system
   that can lock out the last global administrator is a worse failure than no
   permission system.
3. **Absence means unrestricted.** An administrator with no ability rows at all
   has every ability within their domains. The feature is opt-in per
   administrator, so enabling it cannot silently strip authority from people
   who already had it, and an installation that never configures anything
   behaves exactly as it does today.

Abilities are a **PHP enum**, never a database enum and never free text
(`standards/laravel/architecture.md` §8): adding one is a code change, which is
what makes the set reviewable.

## Why not `spatie/laravel-permission`

The package was considered and declined, twice — once when it was suggested for
roles, and again here.

Its model is `(actor, role, permission)`. Mailward's is
`(actor, **domain**, ability)`: the same person may create mailboxes in one
domain and only reset passwords in another. Expressing that with the package
means either a role per domain, which multiplies rows and hides the domain in a
string, or its teams feature, which is a different concept bent into shape.

The hand-rolled version is one table, one enum and one lookup. The user's own
standard puts the bar at "roles are dynamic and managed by users" — these are —
but also at whether the abstraction pays for itself, and here it does not.

`standards/laravel/authorization.md` remains satisfied: the decision still lives
in policies, and no call site asks about a role.

## Consequences

**Gained**

- First-line support can reset a password without being able to delete an
  account — the case that motivated this
- An iRedMail upgrade cannot touch any of it
- Nothing here can disagree with the mail server about who administers a domain,
  because it never answers that question

**Paid**

- Every policy consults two things rather than one
- A new failure mode: an administrator with a domain grant and no ability in it
  signs in and can see but not act. The interface must make that legible rather
  than presenting controls that always refuse — `docs/policies/authorization.md`
  §4 already says a hidden control is not a security boundary, and this is where
  it earns its keep
- Ability rows are keyed by address with no foreign key, so they are cleaned up
  explicitly when a mailbox is deleted, exactly like the other Mailward-side
  rows (`docs/02-domain.md` §13)
- The set of abilities is now part of the product's surface. Adding one is a
  migration-free code change, but removing one is a behaviour change for anyone
  who granted it
