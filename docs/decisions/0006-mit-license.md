# 0006 — MIT licence, and the clean-room rule

Status: accepted — 2026-08-14

## Context

Mailward covers management features that iRedMail sells through the commercial
iRedAdmin-Pro. That is lawful: functionality is not protected by copyright.
Brazilian Lei 9.609/98 art. 6º, III states that similarity arising from the
functional characteristics of an application is not an infringement; the same
principle holds in the EU (CJEU, *SAS Institute v World Programming*) and the
US (17 USC §102(b)).

What is protected is the expression — the source code — and iRedAdmin-Pro ships
its source to customers under a licence forbidding redistribution and resale,
including of modified versions.

iRedMail itself, and the schema Mailward reads, are GPL and public.

## Decision

Mailward is released under the **MIT licence**, on GitHub at
`marcioelias/mailward`.

The following rules are binding on the project and on contributors:

1. **No iRedAdmin-Pro source may be consulted**, by anyone, at any time. Not to
   compare behaviour, not to understand a format. Third-party repositories
   republishing that source are equally off limits
2. **No code is copied from iRedMail or iRedAdmin.** Both are GPL; copying
   would make Mailward a derivative work and MIT would no longer be available.
   Reading the GPL schema to learn the data model is fine — a schema is not a
   derivative work of the software that creates it. Reimplementing an algorithm
   after understanding it is fine. Pasting is not
3. **Naming and branding.** "Mailward" is the product. "iRedMail" appears only
   descriptively, to say what Mailward works with. No iRedMail logos, no
   documentation text, no screenshots. The README carries an explicit
   "not affiliated with iRedMail" notice
4. **The public git history is the record.** It is the evidence of independent
   development, and it starts at the first commit

## Consequences

- MIT was chosen over AGPL because adoption matters more here than preventing
  someone from operating a closed service on top of it. There is no commercial
  model to protect
- MIT is incompatible with copying any GPL code, which makes rule 2 a licence
  constraint rather than a preference
- OQ-03 (the `maildir` path algorithm) must be re-derived from observed
  behaviour on a real install and reimplemented — not lifted from iRedMail's
  GPL source
</content>
