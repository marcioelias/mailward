# Authentication

Status: draft

Sources: `docs/01-architecture.md` §2, §3, §5, §7, `docs/02-domain.md` §1.1,
§1.2, §4, §8, §10, §13, `docs/policies/authorization.md` §1, §4, §5, §6, §7,
`docs/decisions/0003-reuse-iredmail-admin-model.md`,
`docs/decisions/0005-lowercase-canonical-addresses.md`,
`docs/decisions/0007-configurable-maildir-and-password-scheme.md`,
`docs/reference/schema-type-matrix.md` (D2, D4, D12, D13, D16, D17),
`docs/reference/open-questions-research.md` (OQ-04).

---

## Purpose

Let an administrator sign in to the panel with their existing mail account, and
keep every other mail account on the server out of it.

Mailward has no `users` table. Identity is `vmail.mailbox` and the password is
the account's real mail password (`01-architecture.md` §5,
`decisions/0003`). The panel adds a session, never an account.

This is the security-critical feature of the product: the login form is a
password oracle against every real mailbox on the server, and a correct password
is held by the entire user population, not only by administrators.

## Actors

| Actor | Source | May sign in |
|---|---|---|
| Global admin | `mailbox.isglobaladmin = 1` | yes |
| Domain admin | `mailbox.isadmin = 1` | yes |
| Mail user | any other `mailbox` row | **no** — has a valid password and no access |
| Anonymous visitor | — | may reach the login screen only |

`docs/policies/authorization.md` applies in full and is not restated here. This
feature implements its §6 login gate and narrows nothing in it.

## Business Rules

- **BR-01** — Identity comes from `vmail.mailbox`. The submitted address is
  resolved against `mailbox.username` on the `vmail` connection. Mailward has no
  `users` table, and the legacy `admin` table is never read
  (`01-architecture.md` §5, `02-domain.md` §8).
- **BR-02** — Authentication and authorization are two separate, ordered steps.
  Step 1 verifies the credentials against `mailbox.password`; **every mail
  account on the server passes it**. Passing step 1 grants nothing on its own
  (`policies/authorization.md` §6, `decisions/0003` Consequences).
- **BR-03** — **A session is created only if step 2 also passes:
  `isadmin = 1` or `isglobaladmin = 1`.** A correct password alone never
  authenticates a panel session. Conflating the two steps admits the entire mail
  user population into the panel and is specified as a critical bug
  (`policies/authorization.md` §6, `01-architecture.md` §5).
- **BR-04** — `active = 0`, or an `expired` date in the past, denies access at
  **both** steps (`policies/authorization.md` §6.3).
- **BR-05** — Expiry is evaluated as `expired > now()`. It is never compared for
  equality against a sentinel constant: the "never expires" sentinel differs per
  driver (`02-domain.md` §1.2, matrix D2). `active`, `isadmin` and
  `isglobaladmin` are bound as `1`/`0`, never as `true`/`false` (matrix D4).
- **BR-06** — A failure at step 2 is recorded as an **authorization** failure,
  not an authentication failure, and returns the same generic message as a wrong
  password (`policies/authorization.md` §6, §7).
- **BR-07** — Unknown address, wrong password, inactive account, expired account
  and insufficient privilege are indistinguishable to the caller: same status,
  same body, same redirect, and **the same observable timing**. Timing is part
  of the requirement, not a refinement of it — the form is a password oracle
  against real mail accounts (`01-architecture.md` §5, `decisions/0003`).
- **BR-08** — The login endpoint is rate limited more aggressively than an
  ordinary application login, and the limit is never relaxed
  (`01-architecture.md` §5, `decisions/0003` Consequences). A throttled request
  is rejected without any query against `vmail`.
- **BR-09** — The submitted address is trimmed and lowercased at the request
  boundary, before validation and before any lookup
  (`decisions/0005`, which names the login form explicitly). Uniqueness and
  case-insensitivity are never delegated to the database: MySQL folds case,
  PostgreSQL does not (matrix D17).
- **BR-10** — The password verifier accepts every scheme the instance is
  configured to support, including:
  - values carrying **no `{SCHEME}` prefix**, which the mail server interprets
    with its fallback (`default_pass_scheme = CRYPT`) and therefore accepts —
    a verifier that requires a prefix rejects accounts the mail server itself
    authenticates (`open-questions-research.md` OQ-04, established fact 3);
  - both spellings of a bcrypt hash, `{CRYPT}$2…` and `{BLF-CRYPT}$2…`, which
    are the same hash under two labels and can both exist in the column
    (same source, established fact 6).
- **BR-11** — Exactly one scheme is *generative*, selected by instance
  configuration; every other supported scheme is only *verifiable*
  (`decisions/0007`). Verification never depends on the generative setting.
- **BR-12** — A stored password in a scheme this instance cannot verify is a
  **hard, visible failure**, never a silent denial (`decisions/0007`). It is not
  reported internally as a credential mismatch, and it is distinguishable from
  one in the log. Where that failure surfaces to an anonymous caller is
  OQ-AUTH-01.
- **BR-13** — Signing in writes nothing to `vmail`: no password rehash and no
  `last_login` update. `last_login` is Dovecot's and is read-only for Mailward
  (`02-domain.md` §10, matrix D13); the password is changed only by the explicit
  administrative action the interface presents as such (`02-domain.md` §4).
- **BR-14** — Sessions, "remember me", 2FA secrets and preferences live in
  Mailward's own database, keyed by email address. No session state is written
  to an iRedMail database and no column is added to `mailbox`
  (`01-architecture.md` §2, §5, `decisions/0002`).
- **BR-15** — A successful sign-in records the last panel login on
  `panel_profiles`, keyed by the address (`02-domain.md` §13).
- **BR-16** — An administrator with `isadmin = 1` and no `domain_admins` rows
  signs in successfully and sees an empty state, not an error
  (`policies/authorization.md` §5, BR-A03).
- **BR-17** — Neither the submitted password nor the stored hash is ever
  logged, returned, placed in an Inertia prop, or written to `audit_log`
  (`standards/security.md` §6 and §10; `docs/features/audit-log.md` BR-04).
- **BR-18** — Every panel route requires an authenticated session and fails
  closed. Authorization is re-evaluated on the server on every request,
  regardless of what the interface offered
  (`policies/authorization.md` §4).
- **BR-19** — **A successful sign-in is recorded in `audit_log`**, as one entry,
  even though it is neither a write to `vmail` nor a failure: a sign-in timeline
  is what an audit reader looks for beside the failures
  (`docs/features/audit-log.md` BR-17, item 3;
  `docs/reference/decisions-needed.md` Q8, answered 2026-08-15). Three
  boundaries this draws, in the same breath, because each is a case someone will
  otherwise assume the opposite of:
  - the `panel_profiles` write the same request performs (BR-15) is **not**
    separately recorded — one sign-in produces one entry, never two
    (`docs/features/audit-log.md` BR-18);
  - a failure at **step 2** is recorded, as an authorization failure, which is
    BR-06 and is unchanged;
  - a failure at **step 1** — unknown address, wrong password, inactive,
    expired, an unverifiable scheme (BR-12) — and a throttled request (BR-08)
    are **not** recorded in `audit_log`. They are authentication outcomes, not
    administrative acts, and they go to the application log. Recording them
    would also give the log a row per guess, which is what a rate limiter is
    for. Neither the entry nor the log line ever carries the password or the
    hash (BR-17).

## Data

**Read — `vmail.mailbox`** (never written by this feature, BR-13):

| Column | Use |
|---|---|
| `username` | the canonical lowercase address, the identity key |
| `password` | verified, never returned (`VARCHAR(255)`) |
| `isadmin`, `isglobaladmin` | the step-2 gate (BR-03) |
| `active`, `expired` | denial at both steps (BR-04, BR-05) |
| `name` | display only |

**Read — `vmail.domain_admins`**: the administered-domain set of a domain admin,
used to build the session's scope (`policies/authorization.md` §1, §2). Not part
of the login gate (BR-03), and see OQ-AUTH-05.

**Written — Mailward's own database**, all keyed by email address
(`02-domain.md` §13):

| Table | Written |
|---|---|
| `sessions` | the panel session |
| `panel_profiles` | last panel login (BR-15) |
| `audit_log` | successful sign-ins, and authorization failures at step 2 (BR-06, BR-19). Step-1 failures and throttled requests are not written here (BR-19) |
| `two_factor_secrets` | read only if 2FA is in v1 — OQ-AUTH-03 |

The remember-me token store is required by `01-architecture.md` §5 but is not
enumerated in `02-domain.md` §13 — OQ-AUTH-04.

## Contracts

Inertia pages and form endpoints. No JSON API is exposed for the panel's own use
(`decisions/0004`). Validation lives in FormRequests, with the address
normalised in `prepareForValidation()` (BR-09).

| Method | Path | Result |
|---|---|---|
| GET | `/login` | `Auth/Login` — guest only; carries no prop that reveals whether any address exists |
| POST | `/login` | fields `email`, `password`, `remember`. Success: 302 to the intended URL or `/dashboard`. Failure: 302 back with the generic message (BR-07). Throttled: 429 (BR-08) |
| POST | `/logout` | 302 to `/login`; the session is invalidated |

Error cases:

- Every denial — unknown address, wrong password, `active = 0`, expired,
  no admin flag — returns the identical generic message (BR-06, BR-07).
- Validation failures on a malformed address return the same generic message,
  not a field-level error that distinguishes "not an address" from
  "no such account" (BR-07).
- Rate limiting is the only response that may differ, and it is keyed and
  thresholded per OQ-AUTH-02.
- An unauthenticated request to any other panel route is redirected to `/login`
  and the target is not rendered (BR-18).

## States

```
anonymous --submit--> [step 1: credentials]
                          |          \
                     pass |           \ fail
                          v            \
                   [step 2: admin flag] +--> denied (generic message, BR-07)
                          |          /
                     pass |         / fail (logged as an authorization failure, BR-06)
                          v
                     authenticated --logout--> anonymous
```

- `denied` is not a state the caller can distinguish between its causes (BR-07).
- Repeated denials lead to `throttled`, from which no `vmail` query is made
  (BR-08).
- `authenticated` carries the actor's scope: global, or the set of administered
  domains, possibly empty (BR-16).
- A 2FA challenge state between step 2 and `authenticated` is not specified
  here — OQ-AUTH-03.
- Forbidden: reaching `authenticated` from step 1 alone (BR-03).

## Acceptance Criteria

- **AC-01** — Given an address that exists in no `mailbox` row, when it is
  submitted with any password, then no session is created and the response is
  the generic message.
- **AC-02** — Given a mailbox with `isadmin = 0`, `isglobaladmin = 0`,
  `active = 1` and an `expired` date in the future, when its **correct**
  password is submitted, then no session is created, the response is the generic
  message, and an authorization-failure entry is recorded — not an
  authentication failure. *(BR-03, BR-06 — the critical-bug criterion.)*
- **AC-03** — Given the three cases of AC-01, AC-02 and a wrong password for an
  administrator, when each is submitted, then the HTTP status, the redirect
  target, the rendered message and the measured response time are
  indistinguishable between them. *(BR-07)*
- **AC-04** — Given a mailbox with `isadmin = 1` and correct credentials, when
  submitted, then a session is created and the response redirects to
  `/dashboard`; the same holds for a mailbox with `isglobaladmin = 1` only.
- **AC-05** — Given an administrator with `active = 0`, when correct credentials
  are submitted, then access is denied with the generic message; the same holds
  for an administrator whose `expired` is in the past.
- **AC-06** — Given an administrator whose `expired` holds that driver's own
  "never expires" sentinel, when correct credentials are submitted, then sign-in
  succeeds on **MySQL and on PostgreSQL**, and no executed query compares
  `expired` to a literal sentinel string. *(BR-05, matrix D2)*
- **AC-07** — Given the stored address `user@example.com`, when
  ` User@Example.COM ` is submitted with the correct password, then sign-in
  succeeds on both drivers and the session's identity is the lowercase form.
  *(BR-09)*
- **AC-08** — Given failed attempts beyond the configured threshold, when a
  further attempt is made, then it is rejected as throttled and no query is
  issued against the `vmail` connection. *(BR-08)*
- **AC-09** — Given a mailbox whose `password` column holds a crypt string with
  **no `{SCHEME}` prefix**, when the correct password is submitted, then sign-in
  succeeds. *(BR-10)*
- **AC-10** — Given the same bcrypt hash stored once as `{CRYPT}$2…` and once as
  `{BLF-CRYPT}$2…`, when the correct password is submitted against each, then
  both verify. *(BR-10)*
- **AC-11** — Given a mailbox whose password scheme this instance cannot verify,
  when the correct password is submitted, then no session is created and the
  failure is recorded as an unsupported-scheme error, distinguishable in the log
  from a credential failure. *(BR-12)*
- **AC-12** — Given a successful sign-in, when it completes, then no INSERT,
  UPDATE or DELETE was issued on the `vmail` connection: `mailbox.password` is
  byte-identical and `last_login` is unchanged. *(BR-13)*
- **AC-13** — Given a successful sign-in, when it completes, then the address's
  `panel_profiles` row records the panel login, and a first-ever sign-in creates
  that row. *(BR-15)*
- **AC-14** — Given an administrator with `isadmin = 1` and zero `domain_admins`
  rows, when they sign in, then the response is 200, the session exists and the
  dashboard renders an empty state rather than an error. *(BR-16)*
- **AC-15** — Given any outcome of any sign-in attempt, when the response, the
  application log and `audit_log` are inspected, then none of them contains the
  submitted password or the stored hash. *(BR-17)*
- **AC-16** — Given no session, when any panel route is requested, then the
  response redirects to `/login` and the target page is not rendered. *(BR-18)*
- **AC-17** — Given an authenticated session, when `POST /logout` is issued,
  then the session is invalidated and the previous session cookie no longer
  authorises any panel route.
- **AC-18** — Given "remember me" is requested and sign-in succeeds, when the
  write completes, then the token is stored in Mailward's database keyed by the
  address, and no column of `mailbox` has changed. *(BR-14)*
- **AC-19** — Given the login page, when it renders, then no prop, error message
  or response header distinguishes an existing address from an unknown one.
  *(BR-07)*
- **AC-20** — Given correct credentials for an account with `isadmin = 1`, when
  the sign-in succeeds, then exactly one `audit_log` entry exists for that
  request — the sign-in — and no second entry exists for the `panel_profiles`
  write it performed. *(BR-19, BR-15)*
- **AC-21** — Given an unknown address, a wrong password for a real
  administrator, an inactive administrator, an expired administrator and a
  throttled request, when each is submitted, then `audit_log` is unchanged by
  all five; and given correct credentials for a mailbox with no admin flag, then
  one authorization-failure entry is written. *(BR-19, BR-06)*

## Out of Scope

- Changing a mailbox password — `docs/features/mailboxes.md` (`02-domain.md` §4).
- Password reset / "forgot password". No reset flow is in the v1 scope
  (`00-overview.md` §5), and `mailbox.recovery_email` is not wired to anything.
- Silent rehash-on-login into the generative scheme (BR-13).
- Self-service sign-in for mail users (`00-overview.md` §5, Later;
  `policies/authorization.md` §1).
- Registration, signup and any form of self-provisioning
  (`00-overview.md` §2).
- Promoting or demoting administrators, and assigning domains — the domain
  admins feature.
- API tokens and any non-session authentication; the REST API is a later scope
  line (`00-overview.md` §5, `decisions/0004`).
- Backend detection and refusal to run against OpenLDAP or an unsupported
  iRedMail version (`decisions/0001`, `00-overview.md` §8) — a bootstrap
  concern, not a login concern.
- The `admin` table, in any form (`02-domain.md` §8).

## Open Questions

- **OQ-AUTH-01** — Where does the unverifiable-scheme failure of BR-12 surface?
  `decisions/0007` requires a hard, visible failure and forbids a silent denial;
  `01-architecture.md` §5 requires an identical response for every failed login.
  Showing an anonymous caller that this address exists but uses an unsupported
  scheme satisfies the first and violates the second. Nothing in `docs/` decides
  which wins — operator-visible only (log, health check, banner for signed-in
  admins), or caller-visible.
- **OQ-AUTH-02** — What are the rate-limiting thresholds, lockout duration and
  key? `01-architecture.md` §5 and `decisions/0003` require "aggressive" and
  "stricter than an ordinary application login" without a number, and nothing
  decides whether the limiter is keyed by IP, by submitted address, or by both.
  Keying by address is itself an existence oracle if the limits differ per key.
- **OQ-AUTH-03** — Is two-factor authentication in v1, and is it optional per
  administrator or enforceable instance-wide? `01-architecture.md` §5 and
  `02-domain.md` §13 provide `two_factor_secrets`, but the v1 scope list in
  `00-overview.md` §5 does not include 2FA. Its answer adds a state between step
  2 and `authenticated`, and two contracts.
- **OQ-AUTH-04** — Which Mailward table holds the remember-me token?
  `01-architecture.md` §5 requires it in Mailward's database keyed by email
  address; `02-domain.md` §13 enumerates no such entity. A column on
  `panel_profiles` and a dedicated table are both consistent with what is
  written.
- **OQ-AUTH-05** — Do `domain_admins.active = 0` or a past `domain_admins.expired`
  revoke a grant? It changes whether such an administrator is denied, or signs in
  with a narrowed scope. `docs/reference/open-questions-research.md` (OQ-02,
  Still unknown) records that nothing sourced says iRedMail reads either column.
- **OQ-AUTH-07** — Is this feature implementable at all before OQ-04 is answered
  against a real install? `docs/reference/open-questions-research.md` states that
  OQ-04 blocks the custom user provider and therefore login itself. The
  verification set of BR-10 and the server's actual `default_pass_scheme` are
  hypotheses until confirmed on the disposable VM.
