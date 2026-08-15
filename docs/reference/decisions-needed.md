# Decisions Needed

> **This sheet is closed.** Every question it raised — **Q1 through Q24** — has
> been answered, and the answers live in the feature documents that own them,
> not here. What remains open is **empirical**: observations only a running
> iRedMail install can supply (**E1**–**E10**), and the contradictions that
> depend on them (**C1**, **C3**–**C6**). Nothing below is waiting on a
> decision. This document is now a record of what was decided and of what still
> has to be measured.

Originally: every question the specification left open, deduplicated across the
nine feature documents and `docs/00-overview.md` §9, grouped by decision rather
than by feature. Answering a question meant **editing the owning feature
document** and deleting its `OQ-` entry; this file was never authoritative and
is not where answers live. Regenerated 2026-08-15 and closed the same day.

`Q<n>` ids exist so an answer can be written as "Q7: option B". The `OQ-` ids
are the real ones and are kept as cross-references.

Implemented and working today: authentication, the domain scope, the domains
feature, audit recording. Where the code has already settled a question it is
marked **resolved by code**; where the code diverges from the specification it
is marked **re-check**.

---

## Already decided

### Before the 2026-08-15 pass

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

### The twenty-four questions, all answered 2026-08-15

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
  **Q17**, whose answer does not assume a collision is refused.
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
- **Q12 — option A** — **An unverifiable password scheme is operator-visible
  only.** The caller receives the same generic denial as every other failure —
  same status, same body, same redirect, same observable timing — so
  `authentication.md` BR-07 is untouched and the login form does not become an
  account-existence oracle. The failure is made visible on three operator
  surfaces instead: the application log, where it is distinguishable from a
  credential mismatch; a **health check** scanning the `{SCHEME}` prefixes in
  `mailbox.password`; and a banner for signed-in administrators. ADR `0007`'s
  "hard, visible failure" is thereby **scoped to operator surfaces**, and the
  ADR is corrected **by addition** — these records are append-only, so the
  correction is dated rather than a rewrite. Answered 2026-08-15; recorded as
  `authentication.md` BR-20, narrowing BR-12, with AC-22 to AC-25, and as
  `0007` "Correction — 2026-08-15". Closed `OQ-AUTH-01`. **Resolved
  contradiction C2.** Opened `00-overview.md` **OQ-06** (is the health check a
  v1 feature). **Resolved by code, half:** `SchemeRegistry` throws,
  `AuthenticateAdministrator` catches it, logs at error level and returns the
  generic denial — the caller-facing half is built; the operator surfaces are
  not.
- **Q13 — option A** — **`isadmin`/`isglobaladmin`, `active` and `expired` are
  re-checked on every request, failing closed.** The admin gate no longer runs
  at login only: every authenticated request re-reads the actor's `mailbox` row
  by primary key and requires it to exist, to be active, to be unexpired and to
  carry an admin flag, and the actor's domain scope is re-derived rather than
  trusted from session state. **Demotion, deactivation and deletion take effect
  immediately** instead of at the end of a 120-minute session, which is what
  makes `authentication.md` BR-18 true as written and the lockout rules of
  `policies/authorization.md` §5 binding rather than advisory. The cost is one
  indexed lookup per request on a connection every request already opens.
  Answered 2026-08-15; recorded as `authentication.md` BR-21, with AC-26 to
  AC-28. Had no `OQ-` id — it was raised by the implementation against BR-18.
  There is a `->todo()` test in `tests/Feature/Auth/LoginTest.php` holding this
  exact gap.
- **Q14 — option A** — **Two-factor authentication is not in v1.** Login stays
  two steps with no challenge state between step 2 and `authenticated`, and no
  enrolment, verification, recovery or enforcement setting exists. The
  `two_factor_secrets` table **stays specified and unbuilt**: no migration
  creates it and nothing reads or writes it, which makes its step in the D1
  cascade a permanent no-op — stated in the owning document rather than implied.
  `remember_token` and `two_factor_secret` stay in the audit redaction list; the
  registered `two-factor` rate limiter is dead configuration and is removed.
  Answered 2026-08-15; recorded as `authentication.md` BR-22, with AC-29. Closed
  `OQ-AUTH-03`.
- **Q15 — option B** — **`domain.maxquota` caps an individual mailbox's
  `quota`.** It does **not** cap the sum of the domain's mailbox quotas: one
  comparison against one row on create and on any update that submits a quota,
  no `SUM`, and therefore none of the second racy aggregate option C would have
  introduced. `0` means unlimited on the cap; a submitted `quota` of `0` is
  refused where the cap is non-zero, because unlimited exceeds every cap;
  lowering a domain's cap rewrites nothing and binds only the next write.
  Answered 2026-08-15; recorded as `mailboxes.md` BR-28, with AC-32 to AC-34.
  Closed `OQ-M6`, and made `dashboard.md`'s exclusion of `maxquota` a stated
  consequence rather than a deferral. **Still blocked by C1 for implementation:**
  no comparison against `maxquota` may ship before **E4** settles the unit of
  `mailbox.quota`, because a cap comparing two columns in different units is
  worse than no cap. The rule is specified and unimplemented until then.
- **Q16 — option A** — **The per-domain limit is counted inside the transaction
  with no lock, and the race is accepted.** Two simultaneous creates in one
  domain may exceed a limit by one; the next create counts the true total and is
  refused, so the excess is bounded by one per race and never compounds.
  Recorded as a **guardrail rather than a guarantee**, so the behaviour is a
  decision and not a defect report waiting to happen — and the two visible
  consequences are stated with it: a listing may show a count greater than its
  limit, and "at limit" is `count >= limit` for exactly that reason. Nothing
  bills on these limits, `0` already means unlimited, and
  `policies/authorization.md` §5 requires a same-transaction check only for the
  last-global-admin case, which is a lockout rule. Answered 2026-08-15; recorded
  as `mailboxes.md` BR-29, with AC-35 and AC-36, and applied to
  `domain.aliases` by reference. Closed `OQ-M7`.
- **Q17 — option B** — **A forwarding or alias-member target is format-checked
  always, and must exist when it is inside a locally hosted domain.** External
  targets are unrestricted — forwarding off the server is the ordinary use, and
  restricting a destination is what a hosting provider does to a customer, which
  `00-overview.md` §3 rules out. There is **no restriction on which domains a
  member may come from**, and none on circular or self-referencing targets: an
  incomplete loop check is not offered in place of none. Locality for addresses
  Mailward *creates* was already settled by Q4=C; this answer governs
  *destinations*, which those rules deliberately did not reach. Because Q4
  permits collisions, the answer states **which tables the `EXISTS` consults**:
  it is satisfied by any one of a `mailbox.username`, an `alias.address`, or a
  `forwardings.address` with `is_alias = 1` — so an address that is only an
  alias counts, and the check is not implemented against `mailbox` alone. A
  domain that exists only as an `alias_domain` row is treated as external and
  accepted. Answered 2026-08-15; recorded as `aliases.md` BR-19 (with AC-21 to
  AC-27) and `mailbox-aliases-forwardings.md` BR-18 (with AC-20 to AC-26).
  Closed `OQ-A8`, `OQ-AL-04`.
- **Q18 — option B** — **A member-less standalone alias is valid, and is flagged
  in the interface.** Creation requires no member, so the create stays a
  single-table write, and removing the last member is allowed, because an alias
  whose members are being replaced passes through the empty state legitimately.
  The cost is stated rather than removed: such an alias accepts mail and
  delivers it nowhere, so the listing and the detail page carry a visible
  warning and the member count is shown beside every alias. Answered 2026-08-15;
  recorded as `aliases.md` BR-20, with AC-28 and AC-29. Closed `OQ-AL-08`.
- **Q19 — option A** — **Forwardings and alias members expose create and delete
  only.** `active` is always written as `1` and never updated; there is no
  per-row toggle, no endpoint and no control, and a row found with `active = 0`
  from outside Mailward is listed like any other rather than hidden. Nothing
  sourced says any iRedMail component honours the column on these rows, and a
  toggle that appears to suspend delivery while mail keeps flowing is a promise
  Mailward cannot keep. Matches `domain-admins.md` BR-10 on the identical doubt.
  No toggle until **E6** shows the column is honoured; exposing it later is
  additive. Answered 2026-08-15; recorded as `aliases.md` BR-21 (with AC-30,
  AC-31) and `mailbox-aliases-forwardings.md` BR-19 (with AC-27 to AC-29).
  Closed `OQ-A6`, `OQ-AL-07`. **Made E6's `forwardings` half informational
  rather than blocking**, which together with Q1 leaves E6 non-blocking in
  full.
- **Q20 — option A** — **The mailbox form exposes a curated subset of the
  `enable*` toggles**: SMTP, POP3, IMAP, delivery (LDA/LMTP), Sieve/ManageSieve
  and SOGo — six toggles, each moving its whole family of plain and secured/TLS
  variants. Every other `enable*` column keeps whatever iRedMail set and is
  **never named in an INSERT or UPDATE**. Two things recorded explicitly:
  `enablesogo` **gates the three SOGo character columns** — on writes `'y'` to
  `enablesogowebmail`, `enablesogocalendar` and `enablesogoactivesync`, off
  writes `'n'`, and they never receive a boolean cast (matrix D5) — and the
  Dovecot internals `enablelib-storage`, `enablequota-status` and
  `enableindexer-worker` are **deliberately unreachable**, not merely hidden.
  Answered 2026-08-15; recorded as `mailboxes.md` BR-30, with AC-37 to AC-39.
  Closed `OQ-M8`.
- **Q21 — option A** — **Account counts include every row, with an "of which
  inactive" figure beside each count.** The headline matches what the listing
  shows, and the obvious follow-up is answered on the same screen; a row both
  inactive and expired is counted once in the breakdown. **The same answer
  applies to the domain count**: a disabled domain is counted and reported as
  inactive, and its accounts still enter every other figure. Answered
  2026-08-15; recorded as `dashboard.md` BR-19, with AC-21 to AC-24. Closed
  `OQ-DASH-02`.
- **Q22 — option A** — **"Last login" is the greater of `imap` and `pop3`.**
  `lda` is excluded because it records delivery rather than a login: an account
  nobody has read for years looks active under `lda` for as long as anything
  still sends to it. Dormant means **no such login within a configurable
  threshold defaulting to 90 days**, with the threshold in force stated on the
  screen; the default has no external basis and is a chosen number. Unreliable
  values (BR-12) never win the comparison and are reported unknown rather than
  zero; never-logged-in accounts are counted and labelled separately from
  dormant ones. Answered 2026-08-15; recorded as `dashboard.md` BR-20, with
  AC-25 to AC-30. Closed `OQ-DASH-04`.
- **Q23 — option A** — **The supported iRedMail range is 1.7.3 (April 2025) and
  later, validated against 1.8.4.** An install below the floor is **refused with
  a clear message** rather than half-working. 1.7.3 is the last release that
  added columns to `mailbox` and `deleted_mailboxes`, `01-architecture.md` §2
  forbids Mailward from adding them, 1.7.2 performed the `utf8mb4` conversion
  the case handling in `0005` depends on, and 1.8.0 is **inside** the range
  because it is the first release whose Dovecot configuration is generation 2.4.
  Two consequences recorded with it: detection must be **inferred from schema
  shape**, because `/etc/iredmail-release` is a file and Mailward has SQL
  connections only; and the real test matrix is two SQL drivers × two Dovecot
  generations, so a second disposable VM is needed before the password hasher
  can be called done. Answered 2026-08-15; recorded in `00-overview.md` §8, with
  §9 updated. Closed `OQ-01`. **Scopes every empirical answer below.**
  **Re-check:** `app/Actions/InspectMailBackend.php` implements the detection
  half — connection, driver, and the presence of the nine tables — and its own
  docblock states that refusing is unspecified; the refusal path and the
  column-shape probe do not exist yet.
- **Q24 — option A** — **When `mailbox.isglobaladmin` and the `domain_admins`
  `ALL` sentinel row have drifted, Mailward reports it in the health check and
  changes nothing.** It **never repairs a `vmail` row during a read**: a silent
  insert or delete would be an unrequested write during a `GET`, outside any
  audited action, contradicting `policies/authorization.md` §7 — and the other
  panel administering the same server would see an administrator appear or
  vanish with no entry anywhere. The authorization decision is unaffected and is
  not re-opened: `Actor::isGlobalAdmin()` reads the flag alone (BR-03), so drift
  cannot change who may do what. A repair remains available through the ordinary
  idempotent endpoints, so the finding is actionable and the action is audited.
  Answered 2026-08-15; recorded as `domain-admins.md` BR-19, with AC-27 and
  AC-28. Closed `OQ-DA-08`. **Moot if E1 refutes the sentinel row entirely** —
  then there is one representation, nothing can drift, and BR-19 is dropped
  rather than reworded.

---

## Empirical — only a running install can answer these

No decision settles these. Observations belong in
`docs/reference/current-iredmail-behaviour.md`, which is documentation-sourced
only and states plainly that nothing in it has been run against a live server.
Every answer is version-scoped to the range decided in **Q23** — 1.7.3 and
later, validated against 1.8.4 (`00-overview.md` §8): record
`/etc/iredmail-release` and `dovecot --version` alongside each observation, and
where a probe is run on more than one install, record it per version.

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
`dashboard.md` OQ-DASH-06. **Q24 rests on it:** if the sentinel row is refuted there is only one
representation, and `domain-admins.md` BR-19 — report the drift, repair nothing
— is dropped rather than reworded.

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
the mail server (**C3**) that the health check of **Q12** must report — the
check itself is now decided (`authentication.md` BR-20); what it reports on the
2.4 path is not.

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
`domain.maxquota` if anything reads it — **that half now matters more than it
did**, because Q15 makes `maxquota` an enforced cap on `mailbox.quota`, and the
two columns must be shown to share a unit before the comparison can ship.

**Blocks** every quota write and every quota figure on the dashboard, and the
**implementation** of Q15 (`mailboxes.md` BR-28), which is specified and
unimplemented until this is settled. No quota value may be written until then;
nothing in the code multiplies or divides by 1048576 today, so the current
behaviour is "whatever the column says".

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
BR-12 — the authorization scope key for a member row. It also decides which
column the existence check of Q17 (`aliases.md` BR-19,
`mailbox-aliases-forwardings.md` BR-18) reads when it consults `forwardings`
for a per-account alias address.

**Resolves** — `mailbox-aliases-forwardings.md` OQ-A1, OQ-A2; `aliases.md`
OQ-AL-02.

### E6 — Is `domain_admins.active` read by anything, and `forwardings.active` on an `is_list` row?

Set `active = 0` on a real per-domain grant, sign in to iRedAdmin, and check
whether the domain is still administrable. Repeat with a past `expired`.
Separately, disable an `is_list` member row and send mail to the alias — does
the member still receive it?

**Informational, not blocking — both halves.** Q1 was answered A (an inactive or
expired grant confers nothing) and Q19 was answered A (no toggle is exposed and
`active` is always written `1`), and both answers are "ignore the column", which
this probe can only reinforce. It stays worth running: a positive result is what
would justify exposing the toggle later, which is additive
(`aliases.md` BR-21, `mailbox-aliases-forwardings.md` BR-19).

### E7 — Delivery when a mailbox and an alias share an address

Create both for the same address and deliver to it. Which wins, or whether
delivery fails, is the empirical half of **Q4**'s collision question.

**Informational, not blocking.** Q4 was answered C on 2026-08-15: collisions are
permitted and Mailward performs no cross-table uniqueness check, so no rule in
any feature document depends on the outcome of this probe. It remains worth
running — the answer is what an administrator should be told when the panel can
see that an address is both a mailbox and an alias (`aliases.md` BR-17,
`mailboxes.md` BR-26 both allow a warning and forbid a refusal). Q17 is answered
and did not need it: the existence check consults `mailbox`, `alias` and
`forwardings` and is satisfied by any one of them, precisely so that it does not
depend on which object delivery prefers. Nothing waits on it.

**Resolves** — nothing that is still open.

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
One of the six is now resolved; five survive, and each names what it is waiting
for.

### C1 — `mailbox.quota`: bytes or mebibytes — **survives**

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
**Q15 raised the stakes rather than settling them**: `mailboxes.md` BR-28 now
compares `mailbox.quota` against `domain.maxquota` directly, so the two columns
must be shown to share a unit before that comparison can ship, and BR-28 says so
in its own text. Resolve with **E4**; then correct `02-domain.md` §2 and §4,
`domains.md` BR-06, `dashboard.md` BR-05, `mailboxes.md` BR-13 and BR-28 in one
pass. **No quota value may be written until this is settled.**

### C2 — Hard, visible failure vs indistinguishable from every other denial — **RESOLVED**

| Side | Said | Citation |
|---|---|---|
| ADR-0007 | A scheme Mailward cannot verify is "a hard failure at login, never a silent denial" | `0007-configurable-maildir-and-password-scheme.md`, Decision; restated as `authentication.md` BR-12 |
| Authentication feature | Unknown address, wrong password, inactive, expired and insufficient privilege are indistinguishable — same status, same body, same redirect and **the same observable timing** | `authentication.md` BR-07 and AC-03, from `01-architecture.md` §5 |

**Resolved by Q12, answered 2026-08-15, option A.** The two statements were
never about the same audience. "Hard and visible" is now scoped to **operator
surfaces** — the application log, the health check, the administrator banner —
and the caller keeps the generic denial of BR-07 unchanged, so the login form
does not become an account-existence oracle. `0007` was corrected **by
addition** (`0007`, "Correction — 2026-08-15"), because these records are
append-only; `authentication.md` gained BR-20 and narrowed BR-12 to point at
it. The code had already chosen BR-07's side, so no shipped behaviour changes;
what was missing, and is now specified, are the operator surfaces. What this
leaves behind is **`00-overview.md` OQ-06**: three of the surfaces live in a
health check that no feature document owns.

### C3 — Unprefixed hashes: accepted by the mail server, or not — **survives**

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
today. **Two of the three things this was waiting on are now decided.** Q23
fixed the range so that 1.8.0 is inside it, which makes the Dovecot 2.4 path a
supported configuration rather than a hypothetical; and Q12 built the operator
surface a deliberate divergence would have to be reported on. What remains is
the observation itself: BR-10 and AC-09 still need a Dovecot-generation
condition, or the behaviour has to be stated as a deliberate divergence with
the health check behind it. Resolve with **E3**. Note the interaction with the
now-resolved C2: an account whose scheme the *server* has disabled is a third
failure category, distinct from both "wrong password" and "Mailward cannot
verify", and `authentication.md` BR-20 currently describes only the second.

### C4 — `maildir` in the glossary vs in the domain model — **survives**

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
`02-domain.md` §4 — which is on the "I will decide these" list below — and
confirm the derivation with **E2**.

### C5 — Detection preferred, but nothing detectable over the only connection — **survives**

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
**Q23 reinforced the diagnosis without fixing the wording**: version detection
too must be inferred from schema shape, for the same reason and over the same
connection (`00-overview.md` §8). The correction of 2026-08-15 added to `0007`
addresses **C2** only and deliberately leaves this bullet standing; a second
dated correction is the way to close it.

### C6 — The `maillists` limit — minor, documentation debt — **survives**

`02-domain.md` §2 makes all three per-domain limits enforceable and requires all
three to surface on the dashboard. `domains.md` BR-05 declines to enforce
`maillists` and `dashboard.md` Out of Scope declines to surface it, both because
mailing lists are unmodelled in v1. Both narrowings are declared openly, so this
is documentation debt rather than a live risk — but `02-domain.md` §2 still
states a rule no feature implements. Q15 narrowed the neighbouring gap rather
than this one: `maxquota` is now an enforced rule (`mailboxes.md` BR-28), so
`maillists` is the only per-domain limit left stored and unenforced, which makes
the single sentence proposed below the whole of the remaining fix.

### Closed since the previous sheet

- **Every write is audited vs no transaction spans both databases** — closed by
  the OQ-AUD-03 decision and implemented: the entry is written after the `vmail`
  commit, and a crash between the two loses an entry rather than inventing one.
- **Why addresses are canonicalised** — closed: `02-domain.md` §1.1 has been
  corrected. Dovecot's `auth_username_format` default lowercases in both
  generations, so a mixed-case row on PostgreSQL is a dead account, not a
  usability wart. This raised the priority of the health check, which **Q12**
  has now specified and `00-overview.md` OQ-06 asks whether v1 ships.
- **C2, hard visible failure vs indistinguishable denial** — closed by **Q12**
  and the dated correction to ADR `0007`. See C2 above.

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
  it necessary, with the staleness stated on the screen. Q21 and Q22 add one
  aggregate per count and one threshold read, which does not change this.
- **Restricting the generative password scheme** — the configured scheme must be
  one Mailward can also verify, and must not be `PLAIN`, `CLEARTEXT` or
  `PLAIN-MD5`. `MAILWARD_PASSWORD_SCHEME` is currently absent from
  `.env.example` and unguarded; both get fixed.
- **Removing the dead `two-factor` rate limiter** (Q14) — Fortify's `features`
  array is empty and no `two_factor_secrets` migration exists, so the registered
  limiter guards a challenge that cannot occur.
