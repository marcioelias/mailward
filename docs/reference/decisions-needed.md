# Decisions Needed

A working list of everything the specification leaves undecided, deduplicated
across the nine feature documents and `docs/00-overview.md` §9 — 78 raw open
questions, 48 distinct decisions.

Derived from those documents; it does not replace them and is not authoritative.
Answering a question here means **editing the owning feature document** and
deleting its `OQ-` entry — not editing this file.

Ids used here (`D`, `E`, `C`, `P`, `A`) exist only for cross-reference inside
this sheet. The `OQ-` ids are the real ones.

Current as of commit `09cbc57`, which added
`docs/reference/current-iredmail-behaviour.md` and reworked `00-overview.md` §9.
That research closes one question outright (A8), reshapes two (E2, E3), turns
OQ-01 into a decision with a proposal (D7), and opens two new contradictions
(C7, C8). It is documentation-sourced and states plainly that **nothing in it is
verified against a running install**, so every entry below that cites it still
needs the probe.

---

## Decided

Answered by the project owner. Each entry records the answer, the date, and the
documents it changed. The feature documents are authoritative; these summaries
are not.

### D1 — What does deleting a parent do to its children? — **decided 2026-08-15**

**Answer: explicit cascade.** Mailward deletes the dependants itself, in one
transaction, because `vmail` has no foreign keys. Not "refuse", not "allow
orphans". Per parent:

- **Domain** → its mailboxes (each through the mailbox cascade below), its
  standalone `alias` accounts, its `alias_domain` rows, the remaining
  `forwardings` rows of the domain, its `domain_admins` rows (BR-A03), and the
  Mailward-side rows keyed by addresses of that domain — except `audit_log`,
  which is append-only and keeps its references (`02-domain.md` §13).
- **Mailbox** → its self-referencing `forwardings` row, its `is_alias` and
  `is_forwarding` rows, rows where the address appears as a `forwarding` target,
  rows where it is an `is_list` member of some alias, plus a `deleted_mailboxes`
  row so iRedMail's cron removes the files, plus the Mailward-side cleanup of
  `02-domain.md` §13.
- **Alias account** → its `is_list` rows, and nothing more: no deletion record
  equivalent to `deleted_mailboxes` is kept, because an alias owns no storage.
- **Demotion of an administrator** → **not** a deletion. The mailbox survives.
  Demotion removes `domain_admins` rows and the flag its form covers, and does
  **not** touch `panel_profiles` or `two_factor_secrets`: the account still
  exists and may be promoted again.

Two consequences written into the feature documents rather than hidden:

- `deleted_mailboxes.maildir` is an absolute path while `mailbox.maildir` is the
  relative tail below `storagebasedirectory` and `storagenode` (C3, E2,
  `current-iredmail-behaviour.md` Q1). The cascade writes the concatenation of
  the three columns. **This derivation is not confirmed against a running
  install** — `mailboxes.md` OQ-M3 holds that one confirmation.
- No transaction spans both databases (`01-architecture.md` §3), so the
  Mailward-side deletes commit first, the `vmail` writes commit last, and the
  whole operation must be idempotent on retry.

**Changed** — `domains.md` (BR-16, BR-17; removed OQ-DOM-04, OQ-DOM-05,
OQ-DOM-06, OQ-DOM-07), `mailboxes.md` (BR-23, BR-24, BR-25; removed OQ-M4;
raised OQ-M9), `mailbox-aliases-forwardings.md` (BR-14; removed OQ-A7),
`aliases.md` (BR-14; removed OQ-AL-06; raised OQ-AL-10), `alias-domains.md`
(BR-10; removed OQ-AD-03), `domain-admins.md` (BR-14; removed OQ-DA-06).

### D2 — What counts against `domain.aliases`? — **decided 2026-08-15**

**Answer: standalone `alias` rows only.** Not `forwardings.is_alias` rows, not
`alias_domain` rows. Those two populations are unbounded by this limit, and by
any other per-domain limit in v1. Every document affected says so explicitly,
including the ones that consequently have **no** limit check, so that absence
reads as a decision rather than an omission.

**Changed** — `domains.md` (BR-18; removed OQ-DOM-09), `aliases.md` (BR-15;
removed OQ-AL-05), `mailbox-aliases-forwardings.md` (BR-15; removed OQ-A5),
`alias-domains.md` (BR-11; removed OQ-AD-05), `dashboard.md` (BR-18; removed
OQ-DASH-03).

### D8 — May a domain admin write a domain record? — **decided 2026-08-15**

**Answer: no. Every write to a `domain` row is global-admin only.** Creating,
editing, disabling, re-enabling and deleting a domain all require
`mailbox.isglobaladmin = 1`. A domain admin keeps the visibility the scope rule
gives them (`docs/policies/authorization.md` §2) over the domains assigned to
them, and no write authority over the domain record itself. Their authority
covers the *contents* of those domains — mailboxes, aliases, forwardings —
which the feature documents owning those tables govern.

The reasoning: creating a domain adds a name to the mail server's namespace and
deleting one destroys every account inside it through the cascade decided as
**D1**. Neither operation is scoped to a domain the actor already administers,
so the scope rule has no domain under which it could authorize them.

This also disposes of the sub-question in D3: nobody but a global admin creates
a domain, so whether a creator is automatically written into `domain_admins`
for what they just created never arises — and no such row is written.

Refusal keeps the shape the endpoints already had: `403` for a domain inside the
actor's scope, `404` for one outside it so the scope does not leak existence,
both logged as authorization failures (`docs/policies/authorization.md` §7).

**Changed** — `domains.md` (BR-19, BR-20, AC-22 to AC-28; Actors, Contracts and
Out of Scope; removed OQ-DOM-01 and OQ-DOM-02). Partially answers **D3**, which
survives for alias domains and domain-admin assignment.

### D9 — What does disabling a domain do to its accounts? — **decided 2026-08-15**

**Answer: nothing. It writes `domain.active = 0` and nothing else.** Mailward
does not touch the `active` flag of any mailbox, alias, forwarding or alias
domain inside it, and re-enabling writes `domain.active = 1` and nothing else.

The reason is reversibility. If disabling also deactivated every mailbox,
re-enabling could not know which accounts were already inactive beforehand, and
would switch back on accounts that were meant to stay off. Preserving that prior
state would mean recording it in Mailward's own database — a mechanism nobody
has asked for.

**This decision depends on an unconfirmed fact** and says so in the feature
document: Postfix and Dovecot must honour the domain-level flag. The
confirmation is **E6**, now the whole of `domains.md` OQ-DOM-03. If the flag
turns out not to be honoured, this decision has to be revisited, because a
"disabled" domain that still receives mail is worse than no disable action at
all.

**Changed** — `domains.md` (BR-21, AC-29, AC-30; States, Contracts and Out of
Scope; narrowed OQ-DOM-03 to the confirmation, keeping the id).

---

## Answer these first

Ranked by how many feature documents each one unblocks.

### D3 — What may a domain admin write, as opposed to see? — **partly decided**

**Unblocks 2 documents.** The domains half is answered — see **D8**: every write
to a `domain` row is global-admin only, and no `domain_admins` row is created as
a side effect of creating a domain. What remains is everything D8 did not reach.

`docs/policies/authorization.md` §2 decides **visibility** for domain-owned
resources. It decides nothing about write authority, and it cannot reach an
object that does not belong to anyone yet.

**What is still open**

| Open question | The choice |
|---|---|
| `alias-domains.md` OQ-AD-04 — may a domain admin create or delete an alias domain pointing at a domain they administer? | An alias domain also adds a name to the server's namespace, which is the reasoning D8 used for domains; but alias domains are attached to a specific target domain the actor does administer, so the answer need not be the same and must be stated, not inherited |
| `domain-admins.md` OQ-DA-01 — may a domain admin assign or remove another administrator on a domain they administer? | Self-propagating authority: a domain admin who may grant the role can widen access to their domain without a global admin. Independent of D8 |

**Why the rest cannot be guessed.** The scope rule has no term for write
authority. Both readings remain consistent with the policy as written, and the
choice is a statement about how much the organisation trusts a domain admin, not
a fact about iRedMail.

**Options for the remainder**

| Option | Consequence |
|---|---|
| Read-mostly, extending D8 — these writes are global-admin-only too | Consistent with D8; a domain admin cannot in practice run a domain alone |
| Domain admins write inside their domains but never change the server's namespace | The middle position, and the one D8 already takes for domains; for OQ-AD-04 it means no, for OQ-DA-01 it means yes |
| Full symmetry inside scope | For OQ-DA-01 it means a domain admin can appoint further administrators of their domain |

Already decided and not reopened: setting or clearing `mailbox.isglobaladmin` is
global-admin-only (`domain-admins.md` BR-11); every write to a `domain` row is
global-admin only (D8).

**Resolves** — `alias-domains.md` OQ-AD-04; `domain-admins.md` OQ-DA-01.
Adjacent to D6. (`domains.md` OQ-DOM-01 and OQ-DOM-02 were resolved by D8.)

---

### D4 — Does `domain_admins.active = 0` or a past `expired` revoke a grant?

**Unblocks 3 documents.**

The table carries `active` and `expired` columns. Nothing sourced says any
iRedMail component reads either (`open-questions-research.md`, "Still unknown",
OQ-02). Mailward has to decide whether it does.

**Why it cannot be guessed.** Half of it is empirical (E7 — does iRedAdmin
honour it), half is a policy choice that stands even if iRedMail ignores the
column: honouring a column nothing else honours means the two panels disagree
about who is an administrator.

**Options**

| Option | Consequence |
|---|---|
| Honour both | Mailward can suspend a grant without deleting the row; diverges from iRedMail if E7 shows the column is dead |
| Ignore both — row existence is the grant | Matches everything currently known; the columns become write-only noise Mailward maintains for form |
| Current interim (`domain-admins.md` BR-10): always write `active = 1`, offer no disable action | Already the specified v1 behaviour; the decision is only whether to keep it |

**Second half of the question:** if honoured, does an administrator whose only
grants are inactive get **denied login**, or does he sign in with an empty scope
(`authentication.md` OQ-AUTH-05 vs `dashboard.md` OQ-DASH-05)? BR-A03 already
requires the empty-state path to exist, so denial would be a new behaviour.

**Resolves** — `domain-admins.md` OQ-DA-04; `authentication.md` OQ-AUTH-05;
`dashboard.md` OQ-DASH-05. The same shape of doubt applies to
`forwardings.active` on an `is_list` row (`aliases.md` OQ-AL-07 → P3).

---

### D5 — May two objects claim the same name, and must an address's domain be local?

**Unblocks 3 documents.**

Four related gaps: may an `alias.address` equal an existing `mailbox.username`;
must the domain part of an alias address exist in `domain`; must a per-user
alias address belong to a domain the server hosts and the actor administers; may
one name be both a `domain` row and an `alias_domain` row.

**Why it cannot be guessed.** `02-domain.md` §3 states the "target domain must
exist" rule for alias domains **only**, and says nothing equivalent anywhere
else. What iRedMail's delivery actually does when a mailbox and an alias share
an address is observable, not decidable — see E8.

**Options**

| Option | Consequence |
|---|---|
| Strict — every address Mailward writes must sit in a domain the server hosts, and no two objects may claim the same address | Predictable; rejects some configurations that iRedMail itself accepts, and needs a cross-table uniqueness check the schema cannot help with |
| Permissive — validate format only, let delivery resolve conflicts | No new checks; Mailward will happily create rows whose behaviour depends on E8 |
| Split — strict on locality, permissive on collisions (or the reverse) | Needs the two halves stated separately in each feature document |

**Resolves** — `aliases.md` OQ-AL-03, OQ-AL-09;
`mailbox-aliases-forwardings.md` OQ-A4; `alias-domains.md` OQ-AD-01.

Note: OQ-A4's *authorization* half is already decided —
`mailbox-aliases-forwardings.md` BR-02 puts the decision on the owning mailbox's
domain, never the destination's. Only "is it allowed at all" is open.

---

### D6 — Who may read the audit log?

**Unblocks 1 document, but blocks it completely.**
`audit-log.md` BR-14 states the feature is not implementable until this is
answered, and `GET /audit-log` has no authorization rule to enforce without it.

`audit_log` is a Mailward-owned entity (`02-domain.md` §13), not a domain-owned
resource, so the scope rule of `docs/policies/authorization.md` §2 does not
reach it as written.

**Why it cannot be guessed.** The only authorization concept in the product does
not apply to this table, and the policy declines to extend itself.

**Options**

| Option | Consequence |
|---|---|
| Global admins only | The feature becomes implementable immediately; a domain admin cannot see who probed their own domain, which is one of the log's stated purposes |
| Domain admins see entries whose target resolves into their domains | Needs a target → domain resolution for every `target_type`, and leaves the unscopable entries below to be hidden or made global-only |
| The above plus their own entries regardless of target | Same cost, plus a second predicate |

**The unscopable residue** either option 2 or 3 must dispose of: sign-in
failures, settings changes, domain-admin assignments and console runs have no
domain. If option 1 is chosen, this sub-question disappears.

**Resolves** — `audit-log.md` OQ-AUD-01.

---

### D7 — The supported iRedMail version range

**Unblocks no feature document — and bounds every entry in the next section.**

`00-overview.md` §8 promises a declared range, runtime detection and refusal to
operate outside it. Nothing declares one yet.

**Why it cannot be guessed.** iRedMail publishes no support-lifetime policy to
anchor it to, so the range is Mailward's own choice, justified by schema shape.

**Proposal already on the table** (`current-iredmail-behaviour.md` §4,
`00-overview.md` OQ-01): **1.7.3 (April 2025) and later, validated against
1.8.4**, because 1.7.3 is the last release that added columns to `mailbox` and
`deleted_mailboxes` and `01-architecture.md` §2 forbids Mailward from adding
them itself. 1.7.2 converted MySQL to `utf8mb4`, which the case handling in
`0005` depends on; 0.9.7 is the older structural floor (the `alias` →
`forwardings` split) and is not proposed.

**Options**

| Option | Consequence |
|---|---|
| 1.7.3+ as proposed | Six columns are guaranteed present; a 1.7.0–1.7.2 deployment is refused rather than half-working |
| Lower the floor below 1.7.3 | A second column set in `schema-type-matrix.md` and conditional reads for `first_name`, `last_name`, `mobile`, `telephone`, `birthday`, `recovery_email` and `deleted_mailboxes.bytes`/`messages` |
| Pin to 1.8.x only | Smallest matrix, excludes every install predating April 2026 |

**Consequence for the test matrix either way.** The axis that actually matters
is the **Dovecot configuration generation**, not the iRedMail version: 2.3 on
most distributions, 2.4 on Debian 13 and Ubuntu 26.04, and they disagree about
unprefixed password hashes (C7). `01-architecture.md` §8 currently names only
the two SQL drivers, so the matrix is two drivers × two Dovecot generations, and
a second disposable VM is needed before the hasher can be called done.

**Resolves** — `00-overview.md` OQ-01.

---

## Empirical — needs a real install

No amount of deciding answers these. Observations belong in
`docs/reference/current-iredmail-behaviour.md`, which now exists but is
documentation-sourced only — it reaches conclusions and states that none of them
has been run against a running install. Commands below are quoted from
`docs/reference/open-questions-research.md` where that document supplies them.

Every answer here is **version-scoped** — record `/etc/iredmail-release`
alongside each observation, and note the Dovecot generation (D7).

### E1 — How is a global admin represented in `domain_admins`?

Sourced but unconfirmed: `mailbox.isglobaladmin = 1` **and** a `domain_admins`
row with the literal domain `'ALL'`
(`open-questions-research.md`, OQ-02, "What is established").

```sql
SELECT username, domain, active, created FROM vmail.domain_admins ORDER BY username, domain;
SELECT DISTINCT domain, LENGTH(domain), HEX(domain) FROM vmail.domain_admins;  -- MySQL
SELECT * FROM vmail.domain WHERE domain = 'ALL';                               -- must return 0 rows
```

Then the behavioural pass that actually decides the write path: clear
`isglobaladmin` leaving the `ALL` row, log in to iRedAdmin; restore it, delete
the `ALL` row, log in again. Whichever alone still grants access is what
iRedAdmin treats as authoritative
(`open-questions-research.md`, OQ-02, "How to confirm").

If refuted — no sentinel row — `domain-admins.md` BR-04 and BR-05 both change,
the `mailward:promote` contract with them, and OQ-DA-05 and OQ-DASH-06
disappear.

**Resolves** — `00-overview.md` OQ-02; `domain-admins.md` OQ-DA-05, OQ-DA-09;
`dashboard.md` OQ-DASH-06. Feeds P19.

### E2 — The maildir value: absolute or relative, and the hash rule

**Reduced.** The decisive branch is closed on paper: Dovecot creates the mail
directory itself and mailbox creation needs no privileged helper — see **A8**.
What remains is the string's shape, which is still unobserved.

```sql
SELECT username, storagebasedirectory, storagenode, maildir, mailboxformat, mailboxfolder
FROM vmail.mailbox;
```

```bash
sudo doveadm user -f home postmaster@example.com   # ground truth for the concatenation
```

Hash rule — create accounts with adversarial local parts using iRedMail's own
tool and read back only the generated column
(`open-questions-research.md`, OQ-03, Step 2):

```bash
bash create_mail_user_SQL.sh abcdef@example.com 'pw' > /tmp/u1.sql   # a/b/c vs a/ab/abc
bash create_mail_user_SQL.sh a@example.com      'pw' > /tmp/u3.sql   # short local part
bash create_mail_user_SQL.sh a.b-c@example.com  'pw' > /tmp/u4.sql   # non-alphanumeric
grep -o "maildir[^,]*" /tmp/u*.sql
```

One confirmatory probe is still worth running, because A8 rests on
documentation: insert a `mailbox` row (plus its self-referencing `forwardings`
row) whose `maildir` directory does not exist, then deliver and log in over
IMAP:

```bash
echo test | sudo /usr/lib/dovecot/dovecot-lda -d probe@example.com
sudo ls -la /var/vmail/vmail1/example.com/probe-mailward/
```

Note while doing it: the `postmaster` maildir is hashed **without** the
timestamp suffix, unlike every account created afterwards
(`current-iredmail-behaviour.md` Q1, fact 7) — so the first account on an
install is not a valid sample of the generator's output.

**Resolves** — `00-overview.md` OQ-03; `mailboxes.md` OQ-M3; C3; and the
`deleted_mailboxes` half of `domains.md` OQ-DOM-05.

### E3 — Password schemes present, and this host's fallback

**Reshaped.** `current-iredmail-behaviour.md` Q2 establishes that a current
iRedMail always writes a prefixed value (`{SSHA512}` on Linux, `{BLF-CRYPT}` on
the BSDs), and that the fallback for unprefixed values is **not** a constant: it
is `CRYPT` on the Dovecot 2.3 path and `PLAIN` on the 2.4 path below Dovecot
2.4.3 — which is what both distributions selecting that path currently ship. See
**C7**. What must be observed is therefore which generation the target install
runs, and what is actually in the column.

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
anything, and whether anything enforces expiry from it (`mailboxes.md` OQ-M5).

Until this is done, login itself is unimplementable
(`open-questions-research.md`, OQ-04, "What it blocks";
`authentication.md` OQ-AUTH-07).

**Resolves** — `00-overview.md` OQ-04; `authentication.md` OQ-AUTH-07;
`mailboxes.md` OQ-M5. Bounds C2 and C5.

### E4 — The unit of `mailbox.quota`

See **C1** for the contradiction. Set a known value and read what Dovecot
enforces:

```sql
UPDATE vmail.mailbox SET quota = 1 WHERE username = 'probe@example.com';
```

```bash
sudo doveadm quota get -u probe@example.com
sudo doveadm user -f quota_rule probe@example.com
```

A limit of 1 MiB proves mebibytes; a limit of 1 byte proves bytes.

**Resolves** — `mailboxes.md` OQ-M1; `dashboard.md` OQ-DASH-01; C1.

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

Blocks `mailbox-aliases-forwardings.md` in its entirety, and BR-12 of
`aliases.md` (the authorization scope key) depends on it.

**Resolves** — `mailbox-aliases-forwardings.md` OQ-A1, OQ-A2;
`aliases.md` OQ-AL-02.

### E6 — What `domain.active = 0` actually stops

**Now a confirmation, not an open choice.** D9 decided that disabling writes
`domain.active = 0` and nothing else, which assumes the mail server honours the
flag. This probe confirms or refutes that assumption. Set it on a test domain,
leaving every account's own `active` at `1` — the state D9 leaves them in — then
attempt authentication and delivery for an account inside it, and read the mail
log:

```bash
sudo doveadm auth test user@disabled.example 'pw'
echo test | sendmail user@disabled.example
```

Both must be refused. If they are, D9 stands: disabling is one UPDATE and is
reversible. If either succeeds, **D9 has to be revisited** — a "disabled" domain
that still receives mail, or still lets its users log in, is worse than no
disable action at all, and the alternative (deactivating every account) brings
back the state-loss problem D9 declined to build a mechanism for.

Also repeat for an alias domain whose target is disabled.

**Resolves** — `domains.md` OQ-DOM-03; `alias-domains.md` OQ-AD-06.

### E7 — Is `domain_admins.active` read by anything?

Set `active = 0` on a real per-domain grant, then sign in to iRedAdmin and check
whether the domain is still administrable. Repeat with a past `expired`.
Empirical half of **D4**.

Separately and identically for `forwardings.active = 0` on an `is_list` row:
disable a member and send mail to the alias — does the member still receive it?
(`aliases.md` OQ-AL-07.)

### E8 — Delivery when a mailbox and an alias share an address

Create both for the same address and deliver to it. Which one wins, or whether
delivery fails, is the empirical half of **D5** (`aliases.md` OQ-AL-03).

### E9 — Values of `alias.accesspolicy`

Free-text `VARCHAR(30)` with nothing constraining it
(`schema-type-matrix.md`, Unverified 10).

```sql
SELECT DISTINCT accesspolicy, COUNT(*) FROM vmail.alias GROUP BY accesspolicy;
```

Then create an alias through iRedAdmin and see what it writes, and whether
Postfix or iRedAPD honours the value. Until answered, Mailward must not present
a fixed list (`aliases.md` BR-11).

**Resolves** — `aliases.md` OQ-AL-01.

### E10 — Values of `domain.transport`

```sql
SELECT DISTINCT transport, COUNT(*) FROM vmail.domain GROUP BY transport;
```

```bash
postconf -M | grep -i dovecot
```

The shipped default is `dovecot`; there is nothing to validate against beyond
length until the real set is known.

**Resolves** — `domains.md` OQ-DOM-10.

---

## Contradictions inside the specification

Two documents Mailward already owns disagree. These are not open questions to be
researched — one of the two statements is already wrong and has to be corrected.

### C1 — `mailbox.quota`: bytes or mebibytes

| Side | Says | Citation |
|---|---|---|
| Mailward | bytes | `02-domain.md` §4 ("`quota` (bytes)") and §2; `dashboard.md` BR-05; `mailboxes.md` BR-13 |
| iRedMail | mebibytes | Dovecot's shipped `user_query` builds `CONCAT('*:bytes=', mailbox.quota*1048576)` — `open-questions-research.md`, OQ-03, established fact 1 |

**What breaks.** The two readings differ by a factor of 1,048,576 and both render
a plausible-looking number. Written as bytes when Dovecot reads MiB, a "1 GB"
mailbox becomes effectively unlimited; the reverse makes every mailbox
unusable. Every quota figure on the dashboard is wrong by the same factor, and
`domain.maxquota` (documented as bytes, `02-domain.md` §2) may or may not share
the unit. **No quota value may be written until this is settled** — resolve with
**E4**. Now also tracked as `00-overview.md` OQ-05.

### C2 — Hard visible failure vs identical response for every failed login

| Side | Says | Citation |
|---|---|---|
| ADR-0007 | A scheme Mailward cannot verify is "a hard failure at login, never a silent denial" | `0007-configurable-maildir-and-password-scheme.md`, Decision; `authentication.md` BR-12 |
| Architecture | Unknown address, wrong password, inactive, expired and insufficient privilege are indistinguishable — same status, same body, **same timing** | `01-architecture.md` §5; `authentication.md` BR-07 |

**What breaks.** Telling an anonymous caller "this account uses a scheme we
cannot verify" confirms the account exists, which is precisely the oracle
`01-architecture.md` §5 exists to close. Obeying BR-07 instead makes the
unverifiable account indistinguishable from a wrong password — the silent denial
0007 forbids. Held open as `authentication.md` OQ-AUTH-01; the likely resolution
is to scope "visible" to operator-visible surfaces (log, health check, banner
for signed-in administrators) and say so in 0007, but that is a decision, not a
reading.

### C3 — `mailbox.maildir`: absolute or relative

| Side | Says | Citation |
|---|---|---|
| Mailward | absolute path | `02-domain.md` §4 ("`maildir` (absolute path)"); `00-overview.md` §6 glossary ("Absolute filesystem path of an account's mail storage") |
| iRedMail | the relative tail below `storagebasedirectory`/`storagenode` | Dovecot's `user_query` concatenates all three; the official migration guide gives the same `CONCAT(...)` — `open-questions-research.md`, OQ-03, established fact 1 |

The confusion is traceable: `deleted_mailboxes.maildir` **is** annotated
"Absolute path of user's mailbox" in the same schema file, and
`mailbox.maildir` is not annotated at all.

**What breaks.** If Mailward writes an absolute path into `mailbox.maildir`,
Dovecot resolves `/var/vmail/vmail1//var/vmail/...` and the account cannot
receive mail. In the other direction, writing the relative value straight into
`deleted_mailboxes.maildir` means iRedMail's removal cron deletes nothing — or
resolves a path nobody intended (`mailboxes.md` OQ-M3). The three columns must
be concatenated on the deletion path and not on the creation path, or the
reverse. Resolve with **E2**; `02-domain.md` §4 and the glossary are corrected
afterwards. Further corroboration for the relative reading:
`current-iredmail-behaviour.md` Q1 fact 1 shows the userdb query returning
`home` as that same three-column concatenation on both Dovecot paths.

### C4 — Every write is audited vs no transaction spans both databases

| Side | Says | Citation |
|---|---|---|
| Authorization policy | "Every write is recorded in `audit_log`" | `docs/policies/authorization.md` §7; `00-overview.md` §5; `audit-log.md` BR-01 |
| Architecture | No transaction may span both databases; the `vmail` write is the last commit | `01-architecture.md` §3; `audit-log.md` BR-15 |

**What breaks.** The two cannot both hold. Writing the entry first records
changes that may never have been committed; writing it last means a crash
between the two leaves a completed `vmail` write with no entry. One failure mode
must be chosen and stated, because it is exactly what determines the log's
evidentiary value — the property `audit-log.md` claims for it. Held as
`audit-log.md` OQ-AUD-03.

### C5 — Detection preferred, but nothing detectable over the only connection

| Side | Says | Citation |
|---|---|---|
| ADR-0007 | "Where the value can be **detected** from the running server rather than entered by hand, detection is preferred" | `0007`, Decision |
| Architecture | Mailward has SQL connections only — `vmail`, `iredapd`, `amavisd` — and runs unprivileged | `01-architecture.md` §3, §6 |
| Research | There is no configured-scheme column in `vmail`; `DEFAULT_PASSWORD_SCHEME` is an installer shell variable and the generating scheme lives in iRedAdmin's Python settings. Storage base directory and node likewise come from `conf/global` | `open-questions-research.md`, OQ-04 established fact 8; OQ-03 established fact 4 |

**What breaks.** Real detection would require reading files or shelling out to
`doveadm`, which makes a runtime dependency out of exactly the thing
`01-architecture.md` §6 says the product does not need. `01-architecture.md` §5
compounds it by saying the hasher generates "the scheme the server is configured
for", as though the server told us. The honest fallbacks are inference from data
Mailward can already see (the modal `{SCHEME}` prefix in `mailbox.password`, the
existing `storagebasedirectory`/`storagenode` values) or plain operator
configuration — but 0007 currently promises more than the architecture permits.

### C7 — Unprefixed hashes: accepted by the mail server, or not

| Side | Says | Citation |
|---|---|---|
| Authentication feature | The verifier **must** accept values with no `{SCHEME}` prefix, because the mail server interprets them with `default_pass_scheme = CRYPT` and therefore accepts them — "a verifier that requires a prefix rejects accounts the mail server itself authenticates" | `authentication.md` BR-10 and AC-09, citing `open-questions-research.md` OQ-04 established fact 3 |
| Current behaviour | True only on the Dovecot 2.3 path. On the 2.4 path iRedMail sets no default scheme at all, and Dovecot's own default is `PLAIN` below 2.4.3 — Debian 13 ships 2.4.1 and Ubuntu 26.04 ships 2.4.2. An unprefixed hash there is compared as **cleartext** and fails. 2.4 additionally disables weak schemes by default, so prefixed `{CRYPT}$1$…` and `{PLAIN-MD5}…` rows stop authenticating too | `current-iredmail-behaviour.md` Q2, facts 6 and 7 |

**What breaks.** BR-10 as written makes Mailward more permissive than the mail
server on exactly the installs where the difference matters. An administrator
would sign in to the panel with a password that no longer collects their own
mail — inverting the product's central premise that panel identity *is* mail
identity, and hiding a broken account instead of surfacing it. BR-10 and AC-09
need a Dovecot-generation condition, or the behaviour has to be stated as a
deliberate divergence with a health check behind it. Note this also interacts
with C2: an account whose scheme the *server* has disabled is a third failure
category, distinct from both "wrong password" and "Mailward cannot verify".

### C8 — Why addresses are canonicalised

| Side | Says | Citation |
|---|---|---|
| Domain model | "iRedMail's shipped Dovecot config does **not** set `auth_username_format`, so **authentication does not normalise anything**" | `02-domain.md` §1.1 |
| ADR-0005, corrected 2026-08-15 | `auth_username_format` is indeed never set, but its **default lowercases** in both generations (`%Lu` in 2.3, `%{user \| lower}` in 2.4), and the 2.4 path lowercases again inside the SQL. The username never reaches the query as typed | `0005-lowercase-canonical-addresses.md`, "Correction — 2026-08-15"; `current-iredmail-behaviour.md` Q3 |

**What breaks.** Nothing about the decision — `0005` stands either way. What
breaks is the severity everything downstream assumes. Under `02-domain.md` §1.1
a mixed-case row on PostgreSQL is a usability wart the user can work around by
typing the exact casing. Under the corrected reading the lookup key is *always*
lower case, so the row can never be matched: the passdb lookup fails, the userdb
lookup fails with it, and the account has no home and receives no mail. **A
mixed-case row on PostgreSQL is a dead account.** `02-domain.md` §1.1 is
uncorrected and is the version most feature documents cite; the health check
`0005` calls "worth building" is more urgent than any feature document
currently treats it.

### C9 — minor: the `maillists` limit

`02-domain.md` §2 makes all three per-domain limits enforceable and requires all
three to surface on the dashboard. `domains.md` BR-05 declines to enforce
`maillists` and `dashboard.md` Out of Scope declines to surface it, both because
mailing lists are unmodelled in v1. Both narrowings are declared openly, so this
is a documentation debt rather than a live risk — but `02-domain.md` §2 still
states a BR that no feature implements.

---

## Product decisions

Genuine "what should the product do" choices, no technical blocker. Grouped by
theme; the long tail.

### v1 scope gates

- **P1 — Is 2FA in v1?** (`authentication.md` OQ-AUTH-03) `two_factor_secrets`
  exists in `02-domain.md` §13 but 2FA is not in the `00-overview.md` §5 scope
  list. In v1 it adds a state between step 2 and `authenticated`, plus enrolment
  contracts; out of v1 it removes a sub-question from D1. Also decide whether it
  is per-administrator or instance-enforced.
- **P2 — Which of the ~30 `enable*` toggles does the form expose?**
  (`mailboxes.md` OQ-M8) The scope line says only "enabled services". Decide the
  exposed subset and whether any move together — in particular whether
  `enablesogo` gates the three SOGo `'y'`/`'n'` columns.
- **P3 — Is per-row enable/disable exposed for forwardings and alias members?**
  (`mailbox-aliases-forwardings.md` OQ-A6, `aliases.md` OQ-AL-07) Create/delete
  only is the smaller surface. Exposing `active` is only meaningful if E7 shows
  something honours it.
- **P4 — Is renaming supported?** (`domains.md` OQ-DOM-08,
  `alias-domains.md` OQ-AD-02) `domain.domain` is denormalised into seven other
  tables with no foreign key, so a rename is a multi-table rewrite. `aliases.md`
  already puts alias renaming out of scope — the same answer for domains and
  alias domains would be consistent.

### Limits and validation

- **P5 — What does `domain.maxquota` constrain?** (`mailboxes.md` OQ-M6) An
  individual mailbox's quota, the sum of the domain's quotas, or nothing.
  `02-domain.md` §2 describes it without giving it a rule; `dashboard.md`
  already excludes it from the dashboard, so only the mailbox-side rule is open.
- **P6 — Must the `domain.mailboxes` limit close the count-then-insert race?**
  (`mailboxes.md` OQ-M7) `docs/policies/authorization.md` §5 requires a
  same-transaction check for the last-global-admin case only. Either the same
  treatment here, or accept that two concurrent creates can exceed a limit by one.
- **P7 — Is a forwarding target validated beyond address format?**
  (`mailbox-aliases-forwardings.md` OQ-A8) Whether a target inside a locally
  hosted domain must be an existing account, and whether self-referencing or
  circular targets between two local mailboxes are rejected.
- **P8 — May an alias member be outside the actor's domains, or off the server?**
  (`aliases.md` OQ-AL-04) The scope rule governs the resource's domain and says
  nothing about a destination. Restricting it makes external distribution lists
  impossible; not restricting it lets a domain admin forward mail anywhere.
- **P9 — Must a standalone alias have at least one member?**
  (`aliases.md` OQ-AL-08) Decides whether a member-less alias is creatable and
  whether removing the last member is refused. A member-less alias is a black
  hole for mail sent to it.

### Audit log shape

- **P10 — Does "every write" include Mailward's own tables?**
  (`audit-log.md` OQ-AUD-02) Read literally it covers `sessions`, `cache` and
  `jobs`, which makes the log unusable. Draw the line explicitly — most likely
  `vmail` writes plus `settings`, `panel_profiles` and `two_factor_secrets`.
- **P11 — One entry per business operation, or per row written?**
  (`audit-log.md` OQ-AUD-04) Creating a mailbox writes two `vmail` rows;
  deleting one writes three plus Mailward-side cleanups. Per operation reads
  better; per row is more faithful.
- **P12 — Are failed writes recorded?** (`audit-log.md` OQ-AUD-05) Validation
  rejections, driver errors, limit refusals, and a change blocked by BR-A01.
  An attempt to delete the last global admin is arguably the single most
  interesting thing the log could hold.
- **P13 — Is a successful sign-in recorded?** (`authentication.md` OQ-AUTH-06)
  It is neither a `vmail` write nor a failure, so §7 does not mandate it — yet it
  is what an audit reader looks for beside the failures. It does write
  `panel_profiles`, which makes P10's line matter here.
- **P14 — What actor and IP are recorded for a console write?**
  (`audit-log.md` OQ-AUD-08, `domain-admins.md` OQ-DA-07)
  `mailward:promote` has no session and no IP, and is the documented escape
  hatch precisely for the moments an audit reader most wants visibility. Decide
  the sentinel actor, or that it is not audited.
- **P15 — Is the log ever pruned, archived or capped?**
  (`audit-log.md` OQ-AUD-06) It is append-only with no retention rule, so it
  grows without bound; any pruning rule is the one operation that contradicts
  BR-05.
- **P16 — Is append-only enforced at the database?** (`audit-log.md` OQ-AUD-07)
  `01-architecture.md` §3 uses grant-level enforcement for `vmail`, but that
  connection also runs migrations, so the technique does not transfer unchanged.

### Administrator lifecycle

- **P17 — What does "demote" write?** (`domain-admins.md` OQ-DA-02) Removing the
  `domain_admins` rows and leaving `isadmin = 1` produces the BR-A03 state — logs
  in, empty screen. Clearing `isadmin` removes panel access entirely. Both are
  consistent with the policy.
- **P18 — What does `mailward:promote <address>` do without `--global`?**
  (`domain-admins.md` OQ-DA-03) BR-A04 documents only the `--global` form.
  Decide whether the command also grants per-domain administration and with what
  argument, or whether the flag is mandatory.
- **P19 — What does Mailward do when it finds the two global-admin
  representations drifted?** (`domain-admins.md` OQ-DA-08) Repair silently,
  report it, or ignore it. Moot if E1 refutes the sentinel row entirely.

### Login hardening

- **P20 — Rate-limit thresholds, lockout duration and key.**
  (`authentication.md` OQ-AUTH-02) "Aggressive" and "stricter than an ordinary
  application login" are the only guidance. Keying by submitted address is
  itself an existence oracle if the limits differ per key; keying by IP alone is
  weak behind a proxy. Numbers are needed, not adjectives.
- **P21 — Which Mailward table holds the remember-me token?**
  (`authentication.md` OQ-AUTH-04) `01-architecture.md` §5 requires it in
  Mailward's database keyed by address; `02-domain.md` §13 enumerates no such
  entity. A column on `panel_profiles` and a dedicated table are both consistent.

### Dashboard and presentation

- **P22 — Do account counts include inactive and expired rows?**
  (`dashboard.md` OQ-DASH-02) And is the breakdown shown. The same answer decides
  whether a disabled domain contributes to the domain count.
- **P23 — What is "last login", and what makes an account dormant?**
  (`dashboard.md` OQ-DASH-04) `last_login` has three columns (`imap`, `pop3`,
  `lda`) — the greatest of the three, or one nominated protocol — and the
  dormancy threshold is undefined.
- **P24 — Are dashboard figures computed per request, or cached with a stated
  staleness?** (`dashboard.md` OQ-DASH-07) Full-table aggregates per page load
  are the one place this product's read pattern stops being trivially cheap.
- **P25 — How is a domain whose `expired` is already past presented?**
  (`domains.md` OQ-DOM-11) `docs/policies/authorization.md` §6 defines expiry
  only for the login gate. v1 offers no transition into or out of it, so the
  question is purely presentational — badge, filter, or nothing.

---

## Already answered

Raised as an open question in one feature document, but decided elsewhere. Each
of these can be closed by editing the owning document, without a decision.

- **A1 — `mailbox-aliases-forwardings.md` OQ-A3** ("may the self-referencing row
  be absent or inactive, to express *forward without keeping a local copy*").
  Decided: no. `02-domain.md` §5 makes the row an invariant of every mailbox —
  "a mailbox created without this row appears correct in every listing and does
  not receive mail" — and `mailboxes.md` BR-04 and this document's own BR-06
  restate it. The feature is therefore not offerable by removing or deactivating
  that row; whether some *other* mechanism should express it is a new product
  question, not this one.
- **A2 — `domain-admins.md` OQ-DA-01**, partly. BR-11 of the same document
  already decides that setting or clearing `mailbox.isglobaladmin` is
  global-admin-only, because `docs/policies/authorization.md` §2 provides no
  domain under which a domain admin could be authorized for it. Only the
  per-domain assignment half remains open (D3).
- **A3 — `domain-admins.md` OQ-DA-04 and `aliases.md` OQ-AL-07**, for v1
  behaviour. BR-10 of `domain-admins.md` already fixes it: Mailward writes
  `active = 1` and offers no disable action until the semantics are known.
  `aliases.md` States does the same for members ("v1 has no third member
  state"). The semantic question (D4, E7) is open; the v1 behaviour is not.
- **A4 — Renaming an alias account.** `aliases.md` Out of Scope decides it
  outright: `address` is the primary key and is referenced by every member row,
  and nothing decides how a rename propagates. Only the domain and alias-domain
  cases remain (P4).
- **A5 — `mailboxes.md` OQ-M6**, partly. `dashboard.md` Out of Scope already
  removes `domain.maxquota` from the dashboard as a limit figure, so only the
  mailbox-side enforcement rule is open (P5).
- **A6 — `dashboard.md` OQ-DASH-03**, partly. The same document already narrows
  "domains at their limit" to the two computable limits, excluding `maillists`
  because mailing lists are unmodelled. Only the alias half is open (D2).
- **A7 — `aliases.md` OQ-AL-04**, for the authorization half.
  `mailbox-aliases-forwardings.md` BR-02 decides that the destination domain of
  a forwarding plays no part in the authorization decision — the owning
  resource's domain does. Only "may a member be outside the server entirely" is
  a product choice (P8).
- **A8 — `mailboxes.md` OQ-M2** ("does Dovecot auto-create the maildir on first
  delivery"), the question `open-questions-research.md` called the single most
  consequential unknown in the specification. Decided:
  `current-iredmail-behaviour.md` Q1 — **creating a row in `mailbox` is
  sufficient**, no filesystem step is required, and `01-architecture.md` §6
  stands as written. The strongest evidence is that iRedMail's own
  `tools/create_mail_user_SQL.sh` emits exactly two `INSERT`s and contains no
  `mkdir`, `chown` or `chmod`. Mailbox creation therefore needs no privileged
  helper, and `mailboxes.md` can drop OQ-M2 once the probe in E2 corroborates
  it. **Two consequences the feature document must absorb:** the directory does
  not exist between account creation and the first delivery or IMAP/POP3 login,
  so anything reading the filesystem to confirm an account must treat absence as
  normal; and the mail tree is `vmail:vmail` mode `0700`, so an unprivileged
  PHP-FPM worker could not create it even if it wanted to.
- **A9 — `domains.md` OQ-DOM-05**, for its architectural half. It was blocked on
  OQ-03 becoming a privileged-helper problem; A8 closes that branch. What
  remains of OQ-DOM-05 is the value written into `deleted_mailboxes.maildir`
  (C3, E2), which is a string question, not an architecture question.
