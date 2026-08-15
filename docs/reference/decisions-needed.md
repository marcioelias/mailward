# Decisions Needed

Every question the specification still leaves open, deduplicated across the nine
feature documents and `docs/00-overview.md` §9, grouped by decision rather than
by feature — one entry per decision, listing every document it touches.
Answering a question means **editing the owning feature document** and deleting
its `OQ-` entry; this file is not authoritative and is not where answers live.
Regenerated 2026-08-15, replacing the previous sheet in full.

`Q<n>` ids exist so an answer can be written as "Q7: option B". The `OQ-` ids
are the real ones and are kept as cross-references. Recommendations are marked
as such: they are never the answer, and where there is no basis to prefer an
option this document says so.

Implemented and working today: authentication, the domain scope, the domains
feature, audit recording. Where the code has already settled a question it is
marked **resolved by code**; where the code diverges from the specification it
is marked **re-check**.

---

## Already decided

Not reopened below.

- **D1** — deleting a parent cascades to its children explicitly: one `vmail`
  transaction, Mailward-side rows committed first, `vmail` last, idempotent on
  retry. *Unblocked* the delete path in `domains.md`, `mailboxes.md`,
  `aliases.md`, `alias-domains.md`, `mailbox-aliases-forwardings.md` and
  `domain-admins.md`. Implemented for domains in
  `app/Actions/Domains/DeleteDomain.php`. Left two gaps — **Q6**.
- **D2** — `domain.aliases` counts standalone `alias` rows only; per-account
  aliases and alias domains are bounded by no per-domain limit in v1.
  *Unblocked* the limit check and the dashboard figure in five documents.
- **D8** — every write to a `domain` row is global-admin only. *Unblocked*
  `domains.md` on authorization entirely. The same question for alias domains
  and administrator assignment is **Q2**.
- **D9** — disabling a domain writes `domain.active = 0` and nothing else;
  re-enabling writes `1`. *Unblocked* the disable/enable path in `domains.md`.
  Rests on **E8**.
- **OQ-AUD-03** — the audit entry is written **after** the `vmail` commit.
  Accepted failure mode: a crash between the two loses an entry, never invents
  one. *Unblocked* `audit-log.md` BR-15 and closed the old contradiction between
  "every write is recorded" and "no transaction spans both databases".
  Implemented in `app/Support/Audit/Audit.php` and its three call sites.
- **OQ-AUTH-02** — login is limited to 5 attempts per minute, keyed by the
  submitted address **and** the IP together. **A chosen default, not a sourced
  one.** *Unblocked* `authentication.md` BR-08. Implemented in
  `app/Providers/FortifyServiceProvider.php`.

---

## The questions

### Authority — who may do what

#### Q1 — Does an inactive or expired `domain_admins` row still grant administration?

**Unblocks 3 documents.** `OQ-DA-04`, `OQ-AUTH-05`, `OQ-DASH-05`.

The table listing which domains an administrator manages carries `active` and
`expired` columns. Nothing sourced says any iRedMail component reads either.
Mailward has to decide whether it does — and if it does, whether an
administrator whose only grants are switched off is refused login or signs in to
an empty screen.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Ignore both; the row's existence is the grant | Matches everything currently known about iRedMail; the two columns become values Mailward writes and never reads | Nothing. It is what the code does today and what `domain-admins.md` BR-10 already specifies for v1 |
| **B** — Honour both; such an administrator signs in with an empty scope | A grant can be suspended without deleting the row; reuses the empty-state path BR-A03 already requires | One predicate in the scope query, plus a toggle in the UI. Mailward and iRedAdmin then disagree about who administers a domain |
| **C** — Honour both; such an administrator is denied login | As B, plus a new denial reason at the login gate | As B, plus a login branch that BR-A03 says must not exist for the no-domains case |

**Recommendation: A.** Honouring a column nothing else honours makes the two
panels disagree, and B's only benefit — suspend without deleting — is already
available by removing the row and re-adding it.

**Re-check:** `Actor::administeredDomains()`
(`app/Support/Authorization/Actor.php`) filters `username` and excludes the
`'ALL'` sentinel, and filters **neither** `active` nor `expired`. That is
currently a gap rather than a decision; answering A makes it correct as written.

Choosing A makes **E6**'s `domain_admins` half informational rather than
blocking. The same shape of doubt about `forwardings.active` on an alias member
row is **Q19**, answered separately.

#### Q2 — May a domain admin write anything beyond the contents of their domains?

**Unblocks 2 documents.** `OQ-AD-04`, `OQ-DA-01`.

D8 settled the domain record: global admin only. Two writes it did not reach —
creating or deleting an alias domain that points at a domain the actor
administers, and assigning or removing another administrator on a domain they
administer. Neither is a fact about iRedMail; it is a statement about how much
the organisation trusts a domain admin.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Both global-admin only | Consistent with D8; a domain admin cannot run a domain unaided | Nothing. The same gate `domains.md` BR-19 already uses |
| **B** — Administrators may be assigned inside one's own domains, but no name may be added to the server's namespace (yes to OQ-DA-01, no to OQ-AD-04) | The middle position, and the one D8 already takes for domains | One policy per feature |
| **C** — Full symmetry inside scope; both allowed | A domain admin can appoint further administrators of their domain, widening access without a global admin | Same code cost, larger blast radius |

**Recommendation: A.** v1 has no way to review or revoke a grant a domain admin
made — there is no notification, and Q1 recommends no suspend — so
self-propagating authority is hard to unwind. Opening this later is additive;
closing it later breaks a workflow administrators have come to rely on.

#### Q3 — Who may read the audit log?

**Unblocks 1 document, and blocks all of it.** `OQ-AUD-01`.
`audit-log.md` BR-14 states the feature is not implementable until this is
answered; `GET /audit-log` has no rule to enforce without it. `audit_log` is a
Mailward-owned entity, so the scope rule of `docs/policies/authorization.md` §2
does not reach it as written.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Global admins only | The feature becomes implementable immediately; a domain admin cannot see who probed their own domain, which is one of the log's stated purposes | Nothing |
| **B** — Domain admins additionally see entries whose target resolves into their domains | A domain admin can audit their own domain | A target → domain resolver for every `target_type`, plus a rule for the entries that have no domain at all |
| **C** — B, plus their own entries regardless of target | As B | As B, plus one predicate |

**Recommendation: A for v1.** It is the only option needing no target → domain
resolver, and B is purely additive later.

Under B or C the **unscopable residue** must be answered in the same breath:
sign-in failures, settings changes, domain-admin assignments and console runs
have no domain — hide them, or make them global-only. **Choosing A makes that
sub-question disappear.**

### Identity and naming

#### Q4 — May two objects claim the same address, and must an address's domain be local?

**Unblocks 3 documents.** `OQ-AL-03`, `OQ-AL-09`, `OQ-A4`, `OQ-AD-01`.

Four gaps, one decision with two halves. **Locality:** must the domain part of
an address Mailward writes already exist in `domain`? **Collisions:** may an
`alias.address` equal an existing `mailbox.username`, and may one name be both a
`domain` row and an `alias_domain` row? `02-domain.md` §3 states the
"target domain must exist" rule for alias domains only, and nothing equivalent
anywhere else.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Strict on both | Predictable; rejects some configurations iRedMail itself accepts | A cross-table uniqueness check across `mailbox`, `alias`, `domain` and `alias_domain` that the schema cannot help with, and that must fold case identically on both drivers (D17) |
| **B** — Permissive on both; validate format only | No new checks; Mailward will create rows whose delivery behaviour is whatever **E7** turns out to be | Nothing, and nothing is caught |
| **C** — Strict on locality, permissive on collisions | The common typo — an address in a domain the server does not host — is refused; conflicts are left to delivery | One `EXISTS` per write |
| **D** — The reverse of C | Collisions refused, foreign domains allowed | As A, without the cheap half |

**Recommendation: C.** Locality is one query, it catches the typo that otherwise
produces a silently dead address, and it matches the rule `02-domain.md` §3
already imposes on `alias_domain.target_domain`. Collision-strictness is
expensive and would forbid arrangements a running iRedMail may resolve sensibly
— which is only knowable from **E7**.

The *authorization* half of OQ-A4 is already decided:
`mailbox-aliases-forwardings.md` BR-02 puts the decision on the owning mailbox's
domain, never the destination's. Only "is it allowed at all" is open here.
**Choosing A or C makes E7 informational rather than blocking. Q17's
recommendation assumes locality is enforced; if B is chosen here, Q17 must be A.**

#### Q5 — Can a domain or an alias domain be renamed?

**Unblocks 2 documents.** `OQ-DOM-08`, `OQ-AD-02`.

`domain.domain` is the primary key and is denormalised into `mailbox.domain`,
`alias.domain`, `forwardings.domain`, `forwardings.dest_domain`,
`domain_admins.domain`, `used_quota.domain` and `last_login.domain`, with no
foreign key to propagate a change. Renaming an alias account is already out of
scope (`aliases.md`).

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — No renames in v1 | Consistent with the alias decision. A rename must be performed as delete-and-recreate, which under D1 destroys every account in the domain — the UI has to say so, not imply a workaround | Nothing |
| **B** — Renames supported | An administrator can correct a domain name | Not a rename but a rewrite of every address on the server: seven denormalised columns, plus `mailbox.username` and `alias.address` which embed the domain, plus `maildir` which embeds it again and which Dovecot resolves to a directory that already exists on disk |
| **C** — Alias domains renameable, domains not | An alias domain has at most one referencing column (`forwardings.dest_domain`, unverified) | Small, but leaves an asymmetry to explain |

**Recommendation: A.** Option B is a data migration disguised as a form field,
and nothing in the v1 scope line asks for it.

### Deletion and lifecycle

#### Q6 — Do deletions also remove the rows that point *at* the deleted object?

**Unblocks 2 documents.** `OQ-M9`, `OQ-AL-10`.

D1 made every deletion an explicit cascade, and the mailbox cascade already
removes inbound rows — rows elsewhere naming the address as a forwarding target
or as an alias member (`mailboxes.md` BR-23, items 4 and 5). It left two places
asymmetric:

- **(a)** deleting a mailbox does **not** remove that account's `domain_admins`
  rows, so a deleted administrator leaves grants naming an account that no
  longer exists — and re-creating the same address silently inherits them.
- **(b)** deleting a standalone alias does **not** remove rows elsewhere naming
  the alias as a forwarding target or as a member of a second alias, so the
  alias address survives as a live routing target after the alias is gone.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Symmetric: both cascades reach inbound rows, and mailbox deletion reaches `domain_admins` | No dangling reference survives any delete; matches what the mailbox cascade already does for `forwardings` | Two more `DELETE` statements, inside transactions that already exist |
| **B** — Leave as written | Ships sooner; leaves rows iRedMail's own tooling also leaves | (a) is a privilege escalation, not untidiness |
| **C** — Fix (a), leave (b) | Closes the escalation, keeps the routing wart | One `DELETE` |

**Recommendation: A.** (a) is a security bug: delete `admin@example.com`,
re-create the address, and the new account silently regains every domain the old
one administered. (b) is the same "looks right, routes wrong" class the mailbox
cascade was written to avoid.

#### Q7 — What does demoting an administrator write, and what does `mailward:promote` do without `--global`?

**Unblocks 1 document.** `OQ-DA-02`, `OQ-DA-03`. Two halves of one lifecycle.

A full demotion removes the `domain_admins` rows — does it also clear
`mailbox.isadmin`? Leaving it set produces the state BR-A03 describes: the
account signs in and sees an empty screen. Clearing it removes panel access
entirely. Separately, `mailward:promote <address>` without `--global`: does it
grant per-domain administration, and with what argument, or is the flag
mandatory?

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Demote clears `isadmin`; `--global` is mandatory | "Demoted" means "no longer has the panel"; the recovery command does exactly one thing | Nothing |
| **B** — Demote leaves `isadmin`; the command takes an optional `--domain=` | Demotion is reversible without re-promoting; the command becomes a general grant tool | A second code path in the command, and a second empty state to test |

**Recommendation: A.** An administrator who can still sign in but can see
nothing is a support ticket; BR-A03's empty state exists for the *domain was
deleted* case, not as a demotion target. Keeping the recovery command
single-purpose is part of what makes BR-A04 trustworthy.

**Note:** `mailward:promote` **does not exist yet** — there is no
`app/Console/Commands` directory. BR-A04 is unimplemented, and the audit layer
already stamps the literal `'console'` as actor for writes made outside a
request (see **Q10**).

### Audit log shape

#### Q8 — What counts as "a write Mailward performs"?

**Unblocks 2 documents.** `OQ-AUD-02`, `OQ-AUD-05`, `OQ-AUTH-06`.

Read literally, "every write" covers `sessions`, `cache` and `jobs`, which makes
the log unusable. Three lines to draw, best drawn together: **(a)** which
tables — `vmail` only, or also Mailward's own `settings`, `panel_profiles`,
`two_factor_secrets`? **(b)** are failed writes recorded — validation
rejections, driver errors, limit refusals, a change blocked by BR-A01? **(c)**
is a *successful* sign-in recorded? It is neither a `vmail` write nor a failure,
yet it is what an audit reader looks for beside the failures — and it writes
`panel_profiles`, which is question (a).

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — `vmail` writes, `settings`, successful sign-ins, and deliberate refusals (BR-A01, per-domain limits). Not driver errors, not validation rejections, not `panel_profiles`/`sessions`/`cache`/`jobs` | The log reads as "what an administrator did or tried to do"; a sign-in timeline exists; an attempt to delete the last global admin is captured | One allowlist and one call at each refusal site |
| **B** — `vmail` writes only, successes only | Smallest log; the single most interesting event in it — the blocked lockout attempt — is absent | Nothing. It is what the code does today |
| **C** — Everything, including validation rejections | Most faithful; a mistyped form fills the log, and volume then makes **Q11** urgent | A hook in the validation layer, which is exactly where the noise comes from |

**Recommendation: A.** It captures intent rather than only outcomes, without
recording every typo, and it draws the table line where the reader's mental
model already is: the mail data plus the panel's own configuration.

**Re-check:** today there are exactly three audit call sites, all in the domains
Actions. Sign-ins, authorization failures and rate-limit rejections go to the
Laravel log, not to `audit_log`. `panel_profiles`, `two_factor_secrets` and
`settings` have no migration at all, so (a) is currently unanswerable in code.
Note `docs/policies/authorization.md` §7 mandates recording authorization
failures and that is **not implemented** — it is not part of this question, it
is a gap to close whichever option is chosen.

#### Q9 — One entry per business operation, or one per row written?

**Unblocks 1 document.** `OQ-AUD-04`.

Creating a mailbox writes two `vmail` rows; deleting a domain writes dozens
through the D1 cascade.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — One entry per business operation, the affected rows summarised in `before`/`after` | The log reads as a list of what an administrator did; a domain deletion is one line naming what it removed | The payload becomes structured rather than one row's columns |
| **B** — One entry per row | Faithful; one domain deletion produces a page of entries, and the operation that caused them is unrecoverable without a correlation id | A correlation id — which is option A with extra steps |

**Recommendation: A.** **Resolved by code for domains:** `DeleteDomain` already
writes exactly one entry for a cascade of any size. This question is only
whether to confirm that as the rule for every feature. If A, the domain deletion
entry should carry the counts it removed — it currently does not.

#### Q10 — What actor and IP are recorded for a write made outside a web request?

**Unblocks 2 documents.** `OQ-AUD-08`, `OQ-DA-07`.

`php artisan mailward:promote <address> --global` has no session and no IP, and
it is the documented escape hatch (BR-A04) — precisely the moment an audit
reader most wants visibility.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Audited under a sentinel actor, with the OS user and hostname in place of the IP | The escape hatch is visible in the log and distinguishable from a web action | Resolving the OS user; trivial |
| **B** — Audited with the target address as the actor | No new concept | The log then claims the promoted account promoted itself |
| **C** — Not audited | Nothing to build | The one write nobody authorised through the panel is the one write with no record |

**Recommendation: A.** The sentinel is honest about what happened, and the OS
user is the only identity the invocation actually has.

**Resolved by code, partially:** `AuditEntry` already stamps the literal
`'console'` as actor when there is no authenticated user, and
`deleted_mailboxes.admin` uses the same fallback. Confirming A means keeping
that sentinel and adding the OS user and hostname.

#### Q11 — Is the audit log ever pruned, archived or capped?

**Unblocks 1 document.** `OQ-AUD-06`.

The log is append-only with no retention rule, so it grows without bound, and
any pruning rule is the one operation that contradicts BR-05.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — No retention in v1; document the growth, offer export later | BR-05 stays absolute and the log's evidentiary value is unqualified | Nothing to build; something to watch |
| **B** — A configurable retention window with a scheduled prune | The table stays bounded; BR-05 weakens to "no entry is modified, and entries older than N are deleted wholesale", which is a different guarantee | A scheduled command and a settings field |
| **C** — Cap by row count | Bounded without a date rule; the cap silently discards the oldest evidence first | As B |

**Recommendation: A.** An append-only log with a delete path is not an
append-only log, and an admin panel's write volume is small.

**Re-check, and this one is live:** `config/activitylog.php` currently carries
the package default `'clean_after_days' => 365`. Under A that must be disabled
explicitly, or the log silently self-prunes against BR-05.

### Login and passwords

#### Q12 — Where does an unverifiable password scheme surface?

**Unblocks 1 document**, plus ADR `0007` and `01-architecture.md` §5. `OQ-AUTH-01`.
Resolves contradiction **C2**; bounded by **C3**.

When a stored hash is in a scheme Mailward cannot verify, `0007` requires "a
hard failure at login, never a silent denial" while `authentication.md` BR-07
requires every denial to be indistinguishable — same status, same body, same
timing. Telling an anonymous caller "we cannot verify this account's scheme"
confirms the account exists.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Operator-visible only: generic denial to the caller, distinguishable in the application log, surfaced in a health check and a banner for signed-in administrators | BR-07 is untouched; "hard and visible" is scoped to operator surfaces, and `0007` is corrected to say so | A health check that scans `mailbox.password` prefixes |
| **B** — Caller-visible | `0007` is satisfied literally | The login form becomes an account-existence oracle, which `01-architecture.md` §5 exists to close |
| **C** — Generic denial, no operator surface at all | Cheapest | The account is dead and nobody is told until a user complains |

**Recommendation: A.** It is the only option that satisfies both documents
rather than picking a winner, and the health check it needs is already wanted
for two other findings: mixed-case rows on PostgreSQL (`0005`), and rows whose
scheme a Dovecot 2.4 server has disabled (**C3**). All three are the same scan.

**Resolved by code, half:** `SchemeRegistry` throws, `AuthenticateAdministrator`
catches it, logs at error level and returns a generic denial. The caller-facing
half of A is already built; only the operator surface and the `0007` wording
correction remain.

#### Q13 — Does losing the administrator flag end a live session?

**Unblocks 1 document.** No `OQ-` id — raised by the implementation against
`authentication.md` BR-18 ("Authorization is re-evaluated on the server on every
request").

The admin gate currently runs at login only. A session created while
`isadmin = 1` survives the flag being cleared, the account being deactivated,
its `expired` date passing, or the mailbox being deleted, until the session
expires on its own (120 minutes).

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Re-check `isadmin`/`isglobaladmin`, `active` and `expired` on every request; fail closed | Demotion, deactivation and deletion take effect immediately; BR-18 is true as written | One indexed `mailbox` lookup per request, on a connection every request already uses |
| **B** — Re-check on a short interval cached in the session | Bounded staleness at a fraction of the cost | A cache key and a staleness figure to justify |
| **C** — Leave as it is: the gate runs at login | Nothing to build | BR-18 has to be reworded to say the opposite of what it says, and Q7's "demote removes panel access" becomes "removes it within two hours" |

**Recommendation: A.** The check is one primary-key lookup, the product's read
pattern is otherwise trivial, and C makes every lockout rule in
`docs/policies/authorization.md` §5 advisory for the length of a session.

There is a `->todo()` test in `tests/Feature/Auth/LoginTest.php` holding this
exact gap.

#### Q14 — Is two-factor authentication in v1?

**Unblocks 1 document.** `OQ-AUTH-03`.

`two_factor_secrets` is enumerated in `02-domain.md` §13, but 2FA is not in the
v1 scope list in `00-overview.md` §5.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Not in v1 | Login stays two steps with no challenge state; the table stays specified and unbuilt | Nothing |
| **B** — In v1, optional per administrator | An administrator can protect the panel independently of their mail password | Enrolment, verification, recovery codes, and a reset path for an administrator who loses their device — a second escape hatch beside `mailward:promote` |
| **C** — In v1, enforceable instance-wide | As B, plus the organisation can require it | As B, plus a setting and a "must enrol before proceeding" gate |

**Recommendation: A**, weakly. The scope list excludes it. But if 2FA is wanted
at all it is markedly cheaper to build now than to retrofit a challenge state
into a shipped login, so this is a scope question worth answering deliberately
rather than by default.

**Re-check:** Fortify's `features` array is empty and there is no
`two_factor_secrets` migration, but a `two-factor` rate limiter is registered
and `remember_token`/`two_factor_secret` are already in the audit redaction
list. Choosing A means removing the dead limiter. Choosing A also makes the
`two_factor_secrets` step of the D1 cascade a permanent no-op, which the feature
documents should say rather than imply.

### Limits and validation

#### Q15 — What does `domain.maxquota` constrain?

**Unblocks 1 document.** `OQ-M6`.

`02-domain.md` §2 describes the column without giving it a rule, and
`dashboard.md` already excludes it as a limit figure, so only the mailbox-side
rule is open.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Nothing; stored, editable, never enforced | Same treatment `maillists` already gets (`domains.md` BR-05); no surprise rejections | Nothing |
| **B** — Caps an individual mailbox's `quota` | An administrator cannot give one account more than the domain allows | One comparison on mailbox create and edit |
| **C** — Caps the sum of the domain's mailbox quotas | A domain cannot be over-allocated | A `SUM` inside every mailbox write transaction, and the same count-then-insert race as **Q16** |

**Recommendation: B.** It is what the column name reads as to an administrator,
it is one comparison, and it introduces no second racy aggregate.

**Blocked by C1.** No `maxquota` rule can be implemented before the quota unit
is settled — the domain column is documented as bytes while `mailbox.quota` may
be mebibytes, and a cap comparing the two in different units is worse than no
cap. Nothing in the code multiplies or divides by 1048576 anywhere today.

#### Q16 — Must the per-domain limit check close the count-then-insert race?

**Unblocks 1 document.** `OQ-M7`.

`docs/policies/authorization.md` §5 requires a same-transaction check for the
last-global-admin case only. Two concurrent creates can exceed
`domain.mailboxes` by one.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Count inside the transaction, no lock; accept the race | The limit is a guardrail: two simultaneous creates can exceed it by one, and the next create is refused | Nothing |
| **B** — Lock the `domain` row for update inside the create transaction | Exact | One `lockForUpdate`, serialised creates within a domain, and a driver behaviour difference to test |

**Recommendation: A**, but **weakly — there is no basis to prefer one** if the
owner treats these limits as contractual rather than advisory. Nothing in the
product bills on them, and `0` already means unlimited.

#### Q17 — Is a forwarding or alias-member target validated beyond address format?

**Unblocks 2 documents.** `OQ-A8`, `OQ-AL-04`.

Two related targets. **(a)** Must a forwarding target inside a locally hosted
domain correspond to an existing account, and are self-referencing or circular
targets between two local mailboxes refused? **(b)** May an alias member be
outside the actor's administered domains, or off the server entirely?

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Format only, both | Forwarding anywhere works; a typo silently creates a bouncing target; a domain admin can forward mail off the server | Nothing |
| **B** — Format only for external targets; a target inside a locally hosted domain must exist. No restriction on member domains | The common typo is caught; external distribution lists still work | One `EXISTS` on the local branch |
| **C** — B, plus refusing circular pairs | A ↔ B loops refused at write time | A graph walk on every write that still cannot see loops formed through aliases |

**Recommendation: B.** Restricting the *destination* is what a hosting provider
does, and `00-overview.md` §3 rules that shape out explicitly — administrators
are staff, not customers. Loop detection is not worth a walk that cannot be
complete.

**Depends on Q4.** B here is the same rule as Q4's locality half. If Q4 is
answered permissively on locality, this must be A or the two contradict.

#### Q18 — Must a standalone alias have at least one member?

**Unblocks 1 document.** `OQ-AL-08`.

A member-less alias accepts mail and delivers it nowhere.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Yes: creation requires a member, and removing the last one is refused | No black-hole address can exist | The create form must write two tables in one transaction, and editing loses a natural intermediate state |
| **B** — No: a member-less alias is valid and flagged in the UI | Create, then add members — the natural order; the black hole is visible rather than impossible | A warning badge |

**Recommendation: B.** A forces a two-table create for an invariant nothing else
depends on, and an alias whose last member was just removed is a legitimate
step in editing it.

#### Q19 — Is per-row enable/disable exposed for forwardings and alias members?

**Unblocks 2 documents.** `OQ-A6`, `OQ-AL-07`.

Both row kinds have an `active` column defaulting to `1`. Nothing sourced says
any iRedMail component reads `forwardings.active` on an `is_list` row.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Create and delete only; always write `active = 1` | Smallest surface; matches what `domain-admins.md` BR-10 already does for the identical doubt | Nothing |
| **B** — Expose the toggle | A member can be suspended without losing it — *if* anything honours the column | A control, and a promise Mailward cannot keep until **E6** says it can |

**Recommendation: A** until E6 shows the column is honoured. A toggle that does
nothing is worse than no toggle. **Choosing A makes E6's `forwardings` half
informational rather than blocking.**

#### Q20 — Which of the ~30 `enable*` service toggles does the form expose?

**Unblocks 1 document.** `OQ-M8`.

The scope line says only "enabled services", and `02-domain.md` §4 enumerates
the columns without grouping them.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — A curated subset: SMTP, POP3, IMAP, delivery (LDA/LMTP), Sieve/ManageSieve, SOGo. The rest keep whatever iRedMail set and are never written | A form an administrator can read; Dovecot internals stay out of reach | One allowlist |
| **B** — All ~30, grouped | Complete control | Thirty checkboxes including `enablelib-storage`, `enablequota-status` and `enableindexer-worker`, which are Dovecot internals no administrator should switch off |
| **C** — One "services" preset per account | Least to get wrong | Loses the per-protocol control the scope line implies |

**Recommendation: A**, and: `enablesogo` gates the three SOGo columns — turning
SOGo off writes `'n'` to `enablesogowebmail`, `enablesogocalendar` and
`enablesogoactivesync`; turning it on writes `'y'`. Those three are character
columns and never receive a boolean cast (matrix D5).

### Dashboard

#### Q21 — Do the account counts include inactive and expired rows?

**Unblocks 1 document.** `OQ-DASH-02`.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Count every row, with an "of which inactive" figure beside each count | The headline number matches what the listing shows, and the follow-up question is answered on the same screen | One extra aggregate per count |
| **B** — Active and unexpired only | The dashboard answers "how much is live" | The count stops matching the listing total, which is a support ticket waiting to happen |
| **C** — Every row, no breakdown | Cheapest | The obvious follow-up needs a second screen |

**Recommendation: A.** The same answer decides the domain count: a disabled
domain is counted and shown as inactive.

#### Q22 — What is "last login", and what makes an account dormant?

**Unblocks 1 document.** `OQ-DASH-04`.

`last_login` has three columns — `imap`, `pop3`, `lda`.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — The greater of `imap` and `pop3`; `lda` excluded. Dormant = no such login within the threshold | "Last login" means a person connected; `lda` records delivery, not a login | Nothing |
| **B** — The greatest of all three | An account nobody has ever read looks active because mail arrives | Nothing, and the figure is wrong |
| **C** — One nominated protocol, configurable | Correct on any install | A setting nobody will change |

**Recommendation: A**, with a 90-day threshold, configurable. **The threshold
itself has no basis** — pick the number you would act on. Values that are
negative, zero or in the future are reported as unknown and never ranked
(`dashboard.md` BR-12), which is already decided.

### Compatibility

#### Q23 — What is the supported iRedMail version range?

**Unblocks no feature document — and scopes every empirical answer below.**
`OQ-01`.

`00-overview.md` §8 promises a declared range, runtime detection and refusal to
operate outside it. Nothing declares one. iRedMail publishes no support-lifetime
policy to anchor it to, so the range is Mailward's own choice.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — 1.7.3 (Apr 2025) and later, validated against 1.8.4 | Six `mailbox` columns and two `deleted_mailboxes` columns are guaranteed present; a 1.7.0–1.7.2 install is refused rather than half-working | The refusal path, which does not exist yet |
| **B** — Lower the floor below 1.7.3 | Older installs are supported | A second column set in `schema-type-matrix.md` and conditional reads for `first_name`, `last_name`, `mobile`, `telephone`, `birthday`, `recovery_email`, `deleted_mailboxes.bytes` and `.messages` — permanently |
| **C** — 1.8.x only | Smallest matrix | Excludes every install predating April 2026 |

**Recommendation: A**, argued from schema shape in
`current-iredmail-behaviour.md` §4: 1.7.3 is the last release that added columns
to `mailbox`, `01-architecture.md` §2 forbids Mailward from adding them, and
1.7.2 performed the `utf8mb4` conversion the case handling in `0005` depends on.
1.8.0 must be **inside** the range, not above it, because it is the first
release whose Dovecot configuration is generation 2.4.

**Consequence whichever option is chosen:** the axis that matters for the
password hasher is the Dovecot configuration generation, not the iRedMail
version (**C3**). `01-architecture.md` §8 names only the two SQL drivers, so the
real test matrix is two drivers × two Dovecot generations, and a second
disposable VM is needed before the hasher can be called done.

**Re-check:** `app/Actions/InspectMailBackend.php` implements the *detection*
half — connection, driver, and the presence of the nine tables — and its own
docblock states that refusing is unspecified. It reads no version string;
`/etc/iredmail-release` is a file, not a column, and the `vmail` connection
cannot see it. Whichever range is chosen, the detection has to be inferred from
schema shape.

#### Q24 — What does Mailward do when the two global-admin representations have drifted?

**Unblocks 1 document.** `OQ-DA-08`.

`mailbox.isglobaladmin = 1` with no `'ALL'` row in `domain_admins`, or the row
with the flag cleared. The two are written independently by iRedMail's tooling.

| Option | What changes in the product | What it costs |
|---|---|---|
| **A** — Report it in the health check of Q12; change nothing | Honest; the administrator decides | One check, on a scan that already exists |
| **B** — Repair it silently on read | The two panels agree again | Mailward writes a `vmail` row nobody asked for, during a `GET`, outside any audited action — contradicting `docs/policies/authorization.md` §7 |
| **C** — Ignore it | Nothing | Two panels disagree about who is an administrator, silently |

**Recommendation: A.**

**Moot if E1 refutes the sentinel row entirely** — then there is only one
representation and nothing can drift. The *read* path is already settled:
`Actor::isGlobalAdmin()` reads the flag alone, so drift cannot affect
authorization today. This question is about the write path, which does not exist
yet.

---

## Empirical — only a running install can answer these

No decision settles these. Observations belong in
`docs/reference/current-iredmail-behaviour.md`, which is documentation-sourced
only and states plainly that nothing in it has been run against a live server.
Every answer is version-scoped: record `/etc/iredmail-release` and
`dovecot --version` alongside each observation (**Q23**).

### E1 — How is a global admin represented in `domain_admins`?

Sourced but unconfirmed: `mailbox.isglobaladmin = 1` **and** a `domain_admins`
row with the literal domain `'ALL'`.

```sql
SELECT username, domain, active, created FROM vmail.domain_admins ORDER BY username, domain;
SELECT DISTINCT domain, LENGTH(domain), HEX(domain) FROM vmail.domain_admins;  -- MySQL
SELECT * FROM vmail.domain WHERE domain = 'ALL';                               -- must return 0 rows
```

Then the behavioural pass that decides the *write* path: clear `isglobaladmin`
leaving the `ALL` row, sign in to iRedAdmin; restore it, delete the `ALL` row,
sign in again. Whichever alone still grants access is what iRedAdmin treats as
authoritative.

**Blocks** the promote/demote Actions and `mailward:promote` (`domain-admins.md`
BR-04, BR-05, BR-A04), and the count that defines "the last global admin"
(BR-A01). **Read path resolved by code:** `Actor` reads the flag only and
excludes `'ALL'` from the administered-domain query, so listings and the
dashboard are already safe either way.

**Resolves** — `00-overview.md` OQ-02; `domain-admins.md` OQ-DA-05, OQ-DA-09;
`dashboard.md` OQ-DASH-06. Feeds **Q24**.

### E2 — The `maildir` value: shape, and the hash rule

```sql
SELECT username, storagebasedirectory, storagenode, maildir, mailboxformat, mailboxfolder FROM vmail.mailbox;
```

```bash
sudo doveadm user -f home postmaster@example.com   # ground truth for the concatenation
```

Hash rule — create accounts with adversarial local parts using iRedMail's own
tool and read back only the generated column:

```bash
bash create_mail_user_SQL.sh abcdef@example.com 'pw' > /tmp/u1.sql   # a/b/c vs a/ab/abc
bash create_mail_user_SQL.sh a@example.com      'pw' > /tmp/u3.sql   # short local part
bash create_mail_user_SQL.sh a.b-c@example.com  'pw' > /tmp/u4.sql   # non-alphanumeric
grep -o "maildir[^,]*" /tmp/u*.sql
```

One confirmatory probe remains for the conclusion that closed OQ-M2 (Dovecot
creates the mail directory itself, so mailbox creation is plain SQL): insert a
`mailbox` row plus its self-referencing `forwardings` row whose `maildir`
directory does not exist, then deliver and log in over IMAP.

```bash
echo test | sudo /usr/lib/dovecot/dovecot-lda -d probe@example.com
sudo ls -la /var/vmail/vmail1/example.com/probe-mailward/
```

The `postmaster` maildir is hashed **without** the timestamp suffix, unlike
every account created afterwards, so the first account on an install is not a
valid sample of the generator's output.

**Blocks** mailbox creation entirely, and confirms the deletion path already
built: `RecordDeletedMailboxes::absoluteMaildir()` concatenates
`storagebasedirectory` + `storagenode` + `maildir` today, **implemented but
unverified**. If the concatenation is wrong, iRedMail's removal cron either
deletes nothing or resolves a path nobody intended.

**Resolves** — `00-overview.md` OQ-03; `mailboxes.md` OQ-M3; contradiction
**C4**.

### E3 — Password schemes present, and this host's Dovecot generation

```sql
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(password, '}', 1), '{', -1) AS scheme, COUNT(*)
FROM vmail.mailbox WHERE password LIKE '{%}%' GROUP BY scheme ORDER BY 2 DESC;
SELECT username, LEFT(password, 8), LENGTH(password)
FROM vmail.mailbox WHERE password NOT LIKE '{%}%';
```

```bash
dovecot --version                                     # 2.3 or 2.4, and which 2.4
grep -r 'default_pass_scheme\|passdb_default_password_scheme' /etc/dovecot/
grep -r 'auth_allow_weak_schemes' /etc/dovecot/       # absent on the 2.4 samples
doveadm pw -l
doveadm auth test probe@example.com 'the password'    # the only end-to-end proof
```

Also settle whether `passwordlastchange` is written on a password change by
anything, and whether anything enforces expiry from it.

**No longer blocks login** — the verifier is implemented and covers `SSHA512`,
`SSHA256`, `SSHA`, `CRYPT`, `BLF-CRYPT`, `SHA512-CRYPT`, `SHA256-CRYPT`,
`MD5-CRYPT`, `PLAIN-MD5`, `PLAIN`, `CLEARTEXT` and unprefixed values (treated as
`CRYPT`), generating `SSHA512` by default. What it still blocks is
**confidence**: `01-architecture.md` §8 requires the fixtures to be hashes
generated by a real iRedMail install, not by Mailward's own code, and none exist
yet. It also decides whether verifying an unprefixed row is a divergence from
the mail server (**C3**) that the health check of **Q12** must report.

**Resolves** — `00-overview.md` OQ-04; `authentication.md` OQ-AUTH-07;
`mailboxes.md` OQ-M5.

### E4 — The unit of `mailbox.quota`

See contradiction **C1**. Set a known value and read what Dovecot enforces:

```sql
UPDATE vmail.mailbox SET quota = 1 WHERE username = 'probe@example.com';
```

```bash
sudo doveadm quota get -u probe@example.com
sudo doveadm user -f quota_rule probe@example.com
```

A limit of 1 MiB proves mebibytes; a limit of 1 byte proves bytes. Repeat for
`domain.maxquota` if anything reads it.

**Blocks** every quota write and every quota figure on the dashboard, and
**Q15**. No quota value may be written until this is settled; nothing in the
code multiplies or divides by 1048576 today, so the current behaviour is
"whatever the column says".

**Resolves** — `mailboxes.md` OQ-M1; `dashboard.md` OQ-DASH-01;
`00-overview.md` OQ-05; **C1**.

### E5 — Direction and domain columns of a `forwardings` row

For `is_alias = 1`, which column holds the alias address and which holds the
mailbox — and how `domain` and `dest_domain` are filled, including when the
target is external. Getting the direction backwards produces rows that look
right and route mail the wrong way.

Create one per-user alias and one forwarding through iRedAdmin, then:

```sql
SELECT address, forwarding, domain, dest_domain, is_forwarding, is_alias, is_list, active
FROM vmail.forwardings ORDER BY id;
```

**Blocks** `mailbox-aliases-forwardings.md` in its entirety, and `aliases.md`
BR-12 — the authorization scope key for a member row.

**Resolves** — `mailbox-aliases-forwardings.md` OQ-A1, OQ-A2; `aliases.md`
OQ-AL-02.

### E6 — Is `domain_admins.active` read by anything, and `forwardings.active` on an `is_list` row?

Set `active = 0` on a real per-domain grant, sign in to iRedAdmin, and check
whether the domain is still administrable. Repeat with a past `expired`.
Separately, disable an `is_list` member row and send mail to the alias — does
the member still receive it?

**Blocks** nothing if **Q1** is answered A and **Q19** is answered A; both
recommendations are "ignore the column", which this probe can only reinforce.
It becomes blocking only if either is answered B or C.

### E7 — Delivery when a mailbox and an alias share an address

Create both for the same address and deliver to it. Which wins, or whether
delivery fails, is the empirical half of **Q4**'s collision question.

**Blocks** nothing if Q4 is answered A or C.

**Resolves** — the observable half of `aliases.md` OQ-AL-03.

### E8 — What `domain.active = 0` actually stops

A confirmation, not an open choice. **D9** decided that disabling writes
`domain.active = 0` and nothing else, which assumes the mail server honours the
flag. Set it on a test domain, leaving every account's own `active` at `1` — the
state D9 leaves them in — then:

```bash
sudo doveadm auth test user@disabled.example 'pw'
echo test | sendmail user@disabled.example
```

Both must be refused. If either succeeds, **D9 has to be revisited**: a
"disabled" domain that still receives mail, or still lets its users sign in, is
worse than having no disable action at all. Repeat for an alias domain whose
target is disabled.

**Blocks** confidence in an already-shipped feature — the disable and enable
endpoints exist and are tested against the database, not against the mail
server.

**Resolves** — `domains.md` OQ-DOM-03; `alias-domains.md` OQ-AD-06.

### E9 — Values of `alias.accesspolicy`

Free-text `VARCHAR(30)` with nothing constraining it.

```sql
SELECT DISTINCT accesspolicy, COUNT(*) FROM vmail.alias GROUP BY accesspolicy;
```

Then create an alias through iRedAdmin and see what it writes, and whether
Postfix or iRedAPD honours the value.

**Blocks** presenting any fixed list of values (`aliases.md` BR-11 forbids one
until this is answered). It does not block the feature.

**Resolves** — `aliases.md` OQ-AL-01.

### E10 — Values of `domain.transport`

```sql
SELECT DISTINCT transport, COUNT(*) FROM vmail.domain GROUP BY transport;
```

```bash
postconf -M | grep -i dovecot
```

The shipped default is `dovecot`; there is nothing to validate against beyond
length until the real set is known. The domains feature ships with free-text
validation on this column today.

**Resolves** — `domains.md` OQ-DOM-10.

---

## Contradictions

Two documents Mailward already owns disagreeing. These are not questions to
research — one of the two statements is already wrong and has to be corrected.

### C1 — `mailbox.quota`: bytes or mebibytes

| Side | Says | Citation |
|---|---|---|
| Mailward | bytes for `domain.maxquota`, unsettled for `mailbox.quota` | `02-domain.md` §2 ("`maxquota` — Max quota for the domain, bytes") and §4 ("The unit of `quota` is **not settled**"); `domains.md` BR-06 ("The domain quota is `domain.maxquota`, in bytes") and its `POST /domains` field table ("`maxquota` — required integer ≥ 0, bytes") |
| iRedMail | mebibytes for `mailbox.quota` | Dovecot's shipped `user_query` builds `CONCAT('*:bytes=', mailbox.quota*1048576)` — `open-questions-research.md` OQ-03, established fact 1 |

**What breaks.** The two readings differ by a factor of 1,048,576 and both render
a plausible number on screen. Written as bytes where Dovecot reads MiB, a "1 GB"
mailbox becomes effectively unlimited; the reverse makes every mailbox unusable
on its first message. Every quota figure on the dashboard is wrong by the same
factor. **`domain.maxquota` is separately asserted as bytes and may not share
the unit** — the domains feature already validates and stores it on that
assumption, so if `maxquota` turns out to be MiB too, shipped data is wrong.
Resolve with **E4**; then correct `02-domain.md` §2 and §4, `domains.md` BR-06,
`dashboard.md` BR-05 and `mailboxes.md` BR-13 in one pass. **No quota value may
be written until this is settled**, and **Q15** cannot be implemented before it.

### C2 — Hard, visible failure vs indistinguishable from every other denial

| Side | Says | Citation |
|---|---|---|
| ADR-0007 | A scheme Mailward cannot verify is "a hard failure at login, never a silent denial" | `0007-configurable-maildir-and-password-scheme.md`, Decision; restated as `authentication.md` BR-12 |
| Authentication feature | Unknown address, wrong password, inactive, expired and insufficient privilege are indistinguishable — same status, same body, same redirect and **the same observable timing** | `authentication.md` BR-07 and AC-03, from `01-architecture.md` §5 ("an identical response for 'unknown address' and 'wrong password'") |

**What breaks.** Telling an anonymous caller "this account uses a scheme we
cannot verify" confirms the account exists, which is precisely the oracle
`01-architecture.md` §5 exists to close — and the login form is a password
oracle against every real mailbox on the server, not only against
administrators. Obeying BR-07 instead makes the unverifiable account
indistinguishable from a wrong password, which is the silent denial `0007`
forbids, and the account stays broken with nobody told. The code has already
chosen BR-07's side: `AuthenticateAdministrator` logs at error level and returns
the generic denial. **Resolved by answering Q12**; whichever option wins, one of
the two documents must be edited rather than left standing.

### C3 — Unprefixed hashes: accepted by the mail server, or not

| Side | Says | Citation |
|---|---|---|
| Authentication feature | The verifier **must** accept values with no `{SCHEME}` prefix, because the mail server interprets them with `default_pass_scheme = CRYPT` and therefore accepts them — "a verifier that requires a prefix rejects accounts the mail server itself authenticates" | `authentication.md` BR-10 and AC-09 |
| Current behaviour | True only on the Dovecot 2.3 path. On the 2.4 path iRedMail sets no default scheme at all, and Dovecot's own default is `PLAIN` below 2.4.3 — Debian 13 ships 2.4.1, Ubuntu 26.04 ships 2.4.2. An unprefixed hash there is compared as **cleartext** and fails. 2.4 additionally disables weak schemes by default, so prefixed `{CRYPT}$1$…` and `{PLAIN-MD5}…` rows stop authenticating too | `current-iredmail-behaviour.md` Q2, facts 6 and 7 |

**What breaks.** BR-10 as written makes Mailward more permissive than the mail
server on exactly the installs where the difference matters. An administrator
signs in to the panel with a password that no longer collects their own mail —
inverting the product's central premise that panel identity *is* mail identity,
and hiding a broken account instead of surfacing it. The verifier already
implements BR-10 as written, including the unprefixed fallback, so this is live
today. BR-10 and AC-09 need a Dovecot-generation condition, or the behaviour has
to be stated as a deliberate divergence with the health check of **Q12** behind
it. Note the interaction with C2: an account whose scheme the *server* has
disabled is a third failure category, distinct from both "wrong password" and
"Mailward cannot verify".

### C4 — `maildir` in the glossary vs in the domain model

| Side | Says | Citation |
|---|---|---|
| Glossary | "Maildir — Absolute filesystem path of an account's mail storage" | `00-overview.md` §6 |
| Domain model, corrected | "**`maildir` is a relative tail, not an absolute path.** Dovecot's shipped `user_query` builds the real location by concatenating `storagebasedirectory`, `storagenode` and `maildir`. Only `deleted_mailboxes.maildir` is absolute" | `02-domain.md` §4; `current-iredmail-behaviour.md` Q1, fact 1 |

**What breaks.** The domain model was corrected and the glossary was not. If a
reader takes the glossary at face value and writes an absolute path into
`mailbox.maildir`, Dovecot resolves `/var/vmail/vmail1//var/vmail/…` and the
account receives no mail; in the other direction, writing the relative value
straight into `deleted_mailboxes.maildir` makes iRedMail's removal cron delete
nothing, or resolve a path nobody intended. The deletion path already
concatenates the three columns. Correct `00-overview.md` §6 to match
`02-domain.md` §4, and confirm the derivation with **E2**.

### C5 — Detection preferred, but nothing detectable over the only connection

| Side | Says | Citation |
|---|---|---|
| ADR-0007 | "Where the value can be **detected** from the running server rather than entered by hand, detection is preferred and the stored value is the override" | `0007`, Decision |
| Architecture | Mailward has SQL connections only — `vmail`, `iredapd`, `amavisd` — and runs unprivileged | `01-architecture.md` §3, §6 |
| Research | There is no configured-scheme column in `vmail`; `DEFAULT_PASSWORD_SCHEME` is an installer shell variable and the generating scheme lives in iRedAdmin's Python settings. Storage base directory and node likewise come from `conf/global` | `open-questions-research.md` OQ-04 established fact 8; OQ-03 established fact 4 |

**What breaks.** Real detection would mean reading files or shelling out to
`doveadm`, which makes a runtime dependency out of exactly the thing
`01-architecture.md` §6 says the product does not need. `01-architecture.md` §5
compounds it by saying the hasher generates "the scheme the server is configured
for", as though the server had told us. The honest fallbacks are inference from
data Mailward can already see — the modal `{SCHEME}` prefix in
`mailbox.password`, the existing `storagebasedirectory`/`storagenode` values —
or plain operator configuration, which is what the code does today
(`MAILWARD_PASSWORD_SCHEME`, defaulting to `SSHA512`, with no detection at all).
`0007` currently promises more than the architecture permits and should be
narrowed to say "inferred from data on the `vmail` connection, or configured".

### C6 — The `maillists` limit — minor, documentation debt

`02-domain.md` §2 makes all three per-domain limits enforceable and requires all
three to surface on the dashboard. `domains.md` BR-05 declines to enforce
`maillists` and `dashboard.md` Out of Scope declines to surface it, both because
mailing lists are unmodelled in v1. Both narrowings are declared openly, so this
is documentation debt rather than a live risk — but `02-domain.md` §2 still
states a rule no feature implements.

### Closed since the previous sheet

- **Every write is audited vs no transaction spans both databases** — closed by
  the OQ-AUD-03 decision and implemented: the entry is written after the `vmail`
  commit, and a crash between the two loses an entry rather than inventing one.
- **Why addresses are canonicalised** — closed: `02-domain.md` §1.1 has been
  corrected. Dovecot's `auth_username_format` default lowercases in both
  generations, so a mixed-case row on PostgreSQL is a dead account, not a
  usability wart. This raises the priority of the health check in **Q12**.

---

## I will decide these unless you object

Cosmetic or internal, no product consequence.

- **`00-overview.md` §6 glossary, `maildir`** (C4) — rewrite to match
  `02-domain.md` §4: relative tail, absolute only in `deleted_mailboxes`.
- **`02-domain.md` §2, `maillists`** (C6) — add the sentence that says the limit
  is stored and not enforced in v1, so the narrowing is stated once at the top
  rather than three times downstream.
- **`mailbox-aliases-forwardings.md` OQ-A3** ("may the self-referencing row be
  absent or inactive, to express *forward without keeping a local copy*") —
  close it as already decided. `02-domain.md` §5 makes the row an invariant of
  every mailbox and BR-06 restates it. Whether some *other* mechanism should
  express that behaviour is a new product question, not this one.
- **`audit-log.md` OQ-AUD-07, append-only enforcement** — convention plus a
  model guard, not a database grant. `01-architecture.md` §3 uses grants for
  `vmail`, but that technique does not transfer: the same connection runs
  migrations. `AuditEntry` gets the same immutability guard `DeletedMailbox`
  already has — `update()` and `delete()` throw.
- **`authentication.md` OQ-AUTH-04, the remember-me store** — keep it deferred,
  which is what the code already does deliberately
  (`Mailbox::getRememberTokenName()` returns `''`, so no token is ever
  persisted). Remove the vestigial `remember` field from the login request. If
  persistent sessions return, they get a dedicated table keyed by address, not a
  column on `panel_profiles`.
- **`domains.md` OQ-DOM-11, presenting an expired domain** — a badge in the
  listing and on the detail page, and a filter. No transition into or out of the
  state, per `domains.md` BR-09.
- **`dashboard.md` OQ-DASH-07, caching the figures** — computed per request. The
  aggregates are indexed counts and sums; add caching when a real install makes
  it necessary, with the staleness stated on the screen.
- **Restricting the generative password scheme** — the configured scheme must be
  one Mailward can also verify, and must not be `PLAIN`, `CLEARTEXT` or
  `PLAIN-MD5`. `MAILWARD_PASSWORD_SCHEME` is currently absent from
  `.env.example` and unguarded; both get fixed.
