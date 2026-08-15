# 0008 — TypeScript pinned to 5.x until vue-tsc supports 7

Status: accepted — 2026-08-15

## Context

`standards/versions.md` requires a new project to start on the latest stable
version of the entire stack. TypeScript 7 is stable and was installed on that
basis.

TypeScript 7 is the native rewrite of the compiler, and it changed the shape of
the published package. `vue-tsc` — the type checker for single-file components,
and the only way to type-check a `.vue` template at all — has not adapted. Its
manifest claims `typescript: >=5.0.0`, so the incompatibility is not caught at
install time; it fails at run time with `ERR_PACKAGE_PATH_NOT_EXPORTED`.

There is no newer `vue-tsc` that resolves it, and dropping the type check is
not acceptable: TypeScript was chosen deliberately in
`0004-inertia-vue.md`, and a type checker that cannot run is the same as not
having one.

## Decision

Pin TypeScript to **`^5.9.3`** — the highest version the whole frontend stack
supports — rather than dropping `vue-tsc` or shipping an unchecked frontend.

This is the exception `standards/versions.md` describes: a package the project
genuinely needs does not yet support the latest major, so the stack settles on
the highest version everything supports, and the reason is written down.

Everything else stays current: Vite 8, Vue 3.5, `@vitejs/plugin-vue` 6.

## Consequences

- `vue-tsc --noEmit` runs, so `.vue` templates and props are type-checked
- The project does not get TypeScript 7's compiler performance
- **What unblocks it:** a `vue-tsc` release that supports the TypeScript 7
  package layout. Re-check by raising the pin and running `vue-tsc --noEmit`;
  if it passes, this record is superseded and the pin is removed
- The pin is on the dev dependency only. Nothing in the shipped application
  depends on the compiler version
