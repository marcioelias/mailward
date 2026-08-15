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
  `app/Actions/Domains/DeleteDomain.php`. Left two gaps, both now closed by
  **Q6**.
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

- **Q1** — A `domain_admins` row that is inactive or expired confers nothing.
  The columns sit on the grant, not on the person, so one lapsing does not take
  the others with it, and an administrator left with no live grant still signs
  in to an empty screen (BR-A03). Answered 2026-08-15; implemented in
  `Actor::administeredDomains()`. Closed `OQ-DA-04`, `OQ-AUTH-05`, `OQ-DASH-05`.
- **Q2** — Every write in the alias-domains and domain-admins features is
  global-admin only, consistent with D8. A domain admin administers the
  contents of their domains and nothing else. Answered 2026-08-15; recorded as
  `alias-domains.md` BR-12 and `domain-admins.md` BR-15. Closed `OQ-AD-04`,
  `OQ-DA-01`.
- **Q3 — option A** — The audit log is readable by **global admins only** in
  v1. A domain admin cannot read it at all, not even entries targeting their own
  domains and not even their own. No target → domain resolver is needed, and the
  "unscopable residue" sub-question — sign-ins, settings changes, domain-admin
  assignments, console runs — disappears with it. Widening is additive later.
  Answered 2026-08-15; recorded as `audit-log.md` BR-16, with AC-17 and AC-18.
  Closed `OQ-AUD-01`, and unblocked `audit-log.md` BR-14 and `GET /audit-log`.
- **Q4 — option C** — **Strict on locality, permissive on collisions.** The
  domain part of every address Mailward writes must already exist in `domain`;
  an `alias_domain` row does not satisfy it, because an alias domain has no
  accounts of its own. Collisions are not refused: an `alias.address` may equal
  an existing `mailbox.username`, and one name may be both a `domain` row and an
  `alias_domain` row. No cross-table uniqueness check is performed anywhere.
  Answered 2026-08-15; recorded as `mailboxes.md` BR-26, `aliases.md` BR-16 and
  BR-17, `mailbox-aliases-forwardings.md` BR-16, `domains.md` BR-23 and
  `alias-domains.md` BR-13. Closed `OQ-AL-03`, `OQ-AL-09`, `OQ-A4`, `OQ-AD-01`.
  Made **E7 informational rather than blocking**, and changed the framing of
  **Q17**, whose answer must not assume a collision is refused.
- **Q5 — option A** — **No renames in v1**, for domains or for alias domains.
  Neither primary key ever appears in an `UPDATE`, and no rename endpoint,
  field or control exists. A rename is delete-and-recreate, which for a domain
  destroys every account inside it through the D1 cascade; the interface says
  that plainly rather than implying a workaround. Answered 2026-08-15; recorded
  as `domains.md` BR-22 (with AC-31, AC-32) and `alias-domains.md` BR-14 (with
  AC-19). Closed `OQ-DOM-08`, `OQ-AD-02`.
- **Q6 — option A** — **Symmetric cascades.** Deleting a mailbox also removes
  that account's `domain_admins` rows; deleting a standalone alias also removes
  the rows elsewhere naming the alias as a forwarding target or as a member of
  another alias. The first is recorded as a security rule with its reason
  stated: without it, deleting `admin@example.com` and re-creating the address
  silently regains every domain the old account administered — a privilege
  escalation, not untidiness. Answered 2026-08-15; recorded as `mailboxes.md`
  BR-27 and BR-23 item 6, and `aliases.md` BR-18, with
  `mailbox-aliases-forwardings.md` BR-17 as the counterpart invariant. Closed
  `OQ-M9`, `OQ-AL-10`.
- **Q7 — option A** — Demoting an administrator **clears `mailbox.isadmin`**, so
  "demoted" means "no longer has the panel" rather than "signs in and sees
  nothing"; BR-A03's empty state is for the deleted-domain case only. And
  `php artisan mailward:promote <address> --global` takes `--global` as a
  **mandatory** flag: there is no per-domain form of the command and no
  `--domain=` option. Answered 2026-08-15; recorded as `domain-admins.md` BR-16
  and BR-17, with AC-22 to AC-25. Closed `OQ-DA-02`, `OQ-DA-03`.
- **Q8 — option A** — A recorded write is: any write to `vmail`, any write to
  Mailward's own `settings`, a **successful sign-in**, and a **deliberate
  refusal** — the BR-A01 last-global-admin block, a per-domain limit refusal, an
  authorization denial. Not recorded: driver errors, validation rejections, and
  writes to `panel_profiles`, `sessions`, `cache` and `jobs`. Answered
  2026-08-15; recorded as `audit-log.md` BR-17 and BR-18 and
  `authentication.md` BR-19. Closed `OQ-AUD-02`, `OQ-AUD-05`, `OQ-AUTH-06`.
- **Q9 — option A** — **One entry per business operation**, never one per row,
  with the affected rows summarised in the before/after payload. A domain
  deletion is one entry naming what it removed, **including the counts** per
  table. No correlation id is needed: the entry is the operation. Answered
  2026-08-15; recorded as `audit-log.md` BR-19, with AC-23. Closed `OQ-AUD-04`.
  Confirms what `DeleteDomain` already does, and adds the counts it does not yet
  carry.
- **Q10 — option A** — A write made outside a web request is audited under a
  **sentinel actor**, with the **OS user and hostname** recorded in place of the
  IP address. The sentinel is never a real address, so no entry claims that the
  promoted account promoted itself. Answered 2026-08-15; recorded as
  `audit-log.md` BR-20 and `domain-admins.md` BR-18. Closed `OQ-AUD-08`,
  `OQ-DA-07`. Keeps the `'console'` sentinel `AuditEntry` already stamps, and
  adds the OS user and hostname to it.
- **Q11 — option B** — **A configurable retention window with a scheduled
  prune.** This **rewrote `audit-log.md` BR-05**, which had made the log
  append-only without qualification: the guarantee is now that no entry is ever
  modified and none is ever deleted individually, while entries older than the
  configured window are removed wholesale by a scheduled job. The window is
  configurable, a value meaning "never prune" exists and is the **default**, and
  the consequence is stated rather than softened — the log's evidentiary value
  now has a horizon, and anything that must outlive it has to be exported first,
  which v1 does not provide. Answered 2026-08-15; recorded as `audit-log.md`
  BR-05 (rewritten) and BR-21, with AC-26 to AC-28 and a revised AC-06 and
  States section. Closed `OQ-AUD-06`. **Live consequence:**
  `config/activitylog.php` carries the package default
  `'clean_after_days' => 365`; BR-21 requires the effective default to be "never
  prune", so that value must be replaced by Mailward's own setting rather than
  inherited.

## The questions

### Authority — who may do what

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
| **C** — Leave as it is: the gate runs at login | Nothing to build | BR-18 has to be reworded to say the opposite of what it says, and the demotion decided in Q7 (Already decided) — clearing `isadmin` so the account no longer has the panel, `domain-admins.md` BR-16 — takes up to two hours to take effect |

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

**Q4 is answered (option C), and it reframes this question in two ways.**
Locality is now enforced for every address Mailward *writes* — `mailboxes.md`
BR-26, `aliases.md` BR-16, `mailbox-aliases-forwardings.md` BR-16 — so B is
available and consistent, and the objection that B would contradict a permissive
Q4 has gone. What is still open is the *target* side, which those rules
deliberately do not reach: a forwarding destination and an alias member.

**This answer must not assume a collision is refused.** Q4 permits an address to
be both a `mailbox` row and an `alias` row, so "a target inside a locally hosted
domain must exist" does not resolve to a single object. Option B has to state
which tables the `EXISTS` consults — `mailbox`, `alias`, both, and whether an
address that is only an `alias` counts as an existing target — or it will be
implemented against `mailbox` alone and silently reject valid alias targets.

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

**Informational, not blocking.** Q4 was answered C on 2026-08-15: collisions are
permitted and Mailward performs no cross-table uniqueness check, so no rule in
any feature document depends on the outcome of this probe. It remains worth
running — the answer is what an administrator should be told when the panel can
see that an address is both a mailbox and an alias (`aliases.md` BR-17,
`mailboxes.md` BR-26 both allow a warning and forbid a refusal), and it feeds
the wording of any answer to **Q17**. Nothing waits on it.

**Resolves** — nothing that is still open. `aliases.md` OQ-AL-03 was closed by
Q4; this probe now only supplies the explanatory half.

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
