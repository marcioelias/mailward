# 0004 — Inertia + Vue 3

Status: accepted — 2026-08-14

## Context

`standards/frontend/choosing.md` makes Inertia + Vue the default for a new
Laravel application, but points server-rendered admin CRUD with low
interactivity towards Blade + Livewire. Mailward is largely CRUD over database
tables, so the tension is real and worth recording rather than assuming.

## Decision

Inertia + Vue 3 with TypeScript.

## Rationale

Two things tip it away from Livewire:

1. Not every screen is CRUD. The roadmap includes a log viewer, the Postfix
   queue, Amavis quarantine and a throttling dashboard — screens with real
   client-side state where a server round trip per interaction is the wrong
   model
2. A REST API is on the roadmap as a genuine second consumer. Inertia keeps
   that path clean: the API becomes a separate surface with its own routes and
   Resources, and never becomes the way the panel talks to its own backend

## Consequences

- Controllers return `Inertia::render()`, never JSON, for the panel's own use
- Validation stays in FormRequests; rules are never duplicated client-side
- Authorization props are for showing and hiding controls only. The server
  decides (`docs/policies/authorization.md` §4)
- No API is written for Mailward's own frontend. When the public API lands, it
  is additive and separate, following `standards/api.md`
- Livewire is not used anywhere in this project. Mixing the two state models is
  explicitly forbidden by the standard
</content>
