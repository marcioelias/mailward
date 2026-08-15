# 0001 — SQL backends only, no OpenLDAP

Status: accepted — 2026-08-14

## Context

iRedMail can be installed with three backends: MySQL/MariaDB, PostgreSQL or
OpenLDAP. Supporting all three is frequently assumed to be a driver-level
concern. It is not: the LDAP backend is a different data model, with a
different schema, different semantics for group membership, and a different
access library. It would roughly triple the surface of every feature.

## Decision

Mailward supports the **SQL backends only** — MySQL/MariaDB and PostgreSQL.
OpenLDAP is out of scope and will not be added without a separate ADR.

## Consequences

- One data model, expressed as Eloquent models over the `vmail` database
- Two drivers to support, not two data models. The MySQL and PostgreSQL schema
  files shipped by iRedMail still differ in types and defaults, so the test
  suite runs against both
- Installations using the LDAP backend cannot use Mailward. This must be stated
  plainly in the README so nobody discovers it after installing
- Detecting the backend at startup and refusing to run against LDAP, with a
  clear message, is preferable to failing obscurely later
