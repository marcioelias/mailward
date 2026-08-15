# 0007 — Maildir layout and password scheme are configuration, not constants

Status: accepted — 2026-08-14

## Context

`01-architecture.md` §5 already requires the password hasher to *verify* every
scheme that may exist on legacy accounts while *generating* only the scheme the
server is configured for. `02-domain.md` §4 (OQ-03) framed the maildir path as
a single algorithm to be re-derived and reimplemented.

Both framings are too narrow, for two independent reasons.

**iRedMail itself makes both configurable.** The installer asks for the
password scheme, and the maildir path is assembled from settings that vary per
installation — the storage base directory, the storage node, and whether the
path is hashed or flat. A single hard-coded algorithm would be correct on one
server and wrong on the next.

**The first real deployment depends on it.** The mail data being migrated comes
from a long-lived FreeBSD install whose maildir layout and password hashes are
deliberately preserved: the target iRedMail will be configured to match the
source so that mail files and password hashes move unchanged. Mailward must
create new accounts in that same preserved format, not in whatever format it
believes to be current.

A hard-coded constant would therefore be wrong on day one of the only
installation that exists.

## Decision

The maildir layout and the password scheme are **instance configuration**, not
constants in the code.

- Mailward supports every option iRedMail supports **on the SQL backends**
  (`0001-sql-backend-only.md`). LDAP-only options are out of scope with the
  backend itself.
- **Password**: every supported scheme is *verifiable*. Exactly one is
  *generative*, selected by configuration. A scheme Mailward cannot verify is a
  hard failure at login, never a silent denial.
- **Maildir**: the path is produced by a generator selected by configuration,
  reading the same inputs iRedMail uses — the storage base directory, the
  storage node, the mailbox folder, and the layout style.
- Configuration lives in Mailward's own database and config, never as a new
  column on an iRedMail table (`0002-separate-application-database.md`).
- Where the value can be **detected** from the running server rather than
  entered by hand, detection is preferred and the stored value is the override.
  A wrong maildir path produces an account Dovecot cannot deliver to, and the
  failure is silent — so the setting is validated at save time, not at first
  use.

The enumerated option sets are not written here. They are established in
`docs/reference/open-questions-research.md` and confirmed against a real
install before either feature is implemented; this ADR records only that they
are configuration.

## Correction — 2026-08-15

The password bullet above says a scheme Mailward cannot verify is "a hard
failure at login, never a silent denial". That sentence is **too broad as
written**, and is left in place because these records are append-only. The
decision it expresses is unchanged; what it was missing is an audience.

What is wrong: read literally, "a hard failure at login" means the failure is
visible to whoever is at the login form — and whoever is at the login form is
anonymous. Telling them that this address exists but uses a scheme we cannot
verify confirms the account exists, which is exactly the oracle
`01-architecture.md` §5 exists to close, and `docs/features/authentication.md`
BR-07 requires every denial to be indistinguishable from every other: same
status, same body, same redirect, same observable timing. The two documents were
in direct contradiction, recorded as **C2** in
`docs/reference/decisions-needed.md`.

The correction: **"hard and visible" is scoped to operator surfaces.** The
caller receives the same generic denial as any other failure. The failure is
made visible instead in the application log — where it is distinguishable from a
credential mismatch — in a health check that scans the `{SCHEME}` prefixes
present in `mailbox.password`, and in a banner shown to signed-in
administrators. What this ADR was protecting against is unaffected: the
prohibition is on the failure being *silent*, not on the caller being told, and
an account in this state is a dead account whose owner cannot collect mail
either, so somebody must be told. Nobody who can act on it is anonymous.

Recorded as `docs/features/authentication.md` BR-20, which also narrows BR-12
there. Whether the health check is a v1 feature with a specification of its own
is `docs/00-overview.md` OQ-06.

Sourced in `docs/reference/decisions-needed.md` Q12, answered 2026-08-15,
option A.

## Consequences

**Gained**

- Mailward works on installs it did not provision, including migrated ones
- The legacy formats of the first deployment are a supported configuration
  rather than a special case in the code
- New schemes and layouts are added as options, not as edits to a constant

**Paid**

- Two extension points to design, test and document instead of two constants
- Every supported combination is a test matrix axis, on top of the two SQL
  drivers `01-architecture.md` §8 already requires
- Misconfiguration becomes a real failure mode. It is mitigated by validating
  at save time and by preferring detection over manual entry, not by trusting
  the administrator
- OQ-03 and OQ-04 are re-scoped: the question is no longer "what is the
  algorithm" but "what is the full set of supported options, and how is each
  one configured and detected"
