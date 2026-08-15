# 0005 — Lower case is the canonical form of every address

Status: accepted — 2026-08-14

## Context

Email case handling is inconsistent across the stack Mailward sits on:

- **RFC 5321 §2.4** — the local part is formally case-sensitive, the domain is
  not. The RFC itself discourages relying on local-part case
- **Postfix** — folds the lookup key to lower case before table lookups;
  `virtual(5)` states delivery decisions use the full recipient address folded
  to lower case. Delivery therefore tolerates mixed case
- **Dovecot** — iRedMail's shipped configuration does **not** set
  `auth_username_format`, so the username reaches the SQL query exactly as the
  client typed it
- **The database** — MySQL's `utf8mb4_general_ci` collation then makes the
  lookup case-insensitive by accident. PostgreSQL, being case-sensitive, does
  not

The result is a defect that depends on the backend: a mixed-case account works
on MySQL and cannot log in on PostgreSQL. iRedMail avoids it by always writing
lower case, but nothing in the schema enforces that.

## Decision

Lower case is the canonical form. Mailward normalises **at the request
boundary**, in `prepareForValidation()` on every FormRequest accepting an
address — trim, then lower case, then validate.

This covers mailboxes, aliases, forwardings, domains, alias domains, domain
admin assignments, and the login form.

Normalisation applies to lookups as well as writes.

## Correction — 2026-08-15

The Dovecot bullet in Context above is **factually wrong**, and is left in
place because these records are append-only. The decision it supports is
unchanged, and is in fact better founded than the original reasoning claimed.

What is wrong: `auth_username_format` is indeed never set by iRedMail, but the
setting is not "unset means pass through". It has a lowercasing **default** in
both configuration generations — `%Lu` in Dovecot 2.3, `%{user | lower}` in
2.4. On the 2.4 path iRedMail lowercases a second time inside the SQL query
itself. The username therefore never reaches the query as the client typed it.

Why this strengthens the decision rather than weakening it: because the lookup
key is *always* lower case, a mixed-case row on PostgreSQL can never be matched
at all. The user cannot work around it by typing the exact casing, and the
userdb lookup fails alongside the passdb one, so the account has no home and
receives no mail.

A mixed-case row on PostgreSQL is therefore not a usability wart. It is a dead
account. The health check described below as "worth building" is accordingly
more urgent than this record originally implied.

Sourced in `docs/reference/current-iredmail-behaviour.md` §Q3, against
iRedMail 1.8.4 and Dovecot's own documentation. Not yet confirmed against a
running install.

## Consequences

- No mixed-case address is ever written to `vmail` by Mailward
- Behaviour is identical on both drivers, which is the point
- Unique indexes are not relied upon to catch case duplicates — they catch them
  on MySQL and miss them on PostgreSQL
- If display casing is ever wanted, it is stored in Mailward's own database.
  Never in `vmail`
- A health check that finds pre-existing mixed-case rows, and offers to
  normalise them, is worth building: it fixes a class of login failure that
  predates Mailward
