---
paths:
  - composer.json
  - package.json
---

# Dependencies

## Require as little as possible
Every runtime dependency lands in every user's app. `require` holds only PHP, the `illuminate/*` components and polyfills. Call outside APIs (OTLP, Firecrawl) with Laravel's `Http` client instead of pulling in an SDK.

## Optional integrations stay optional
Libraries Spectra can work with but doesn't need (the OpenAI SDK, getID3) go in `require-dev` for the tests and in `suggest` with the reason. Code that uses one checks `class_exists()` first and degrades quietly when it is missing.

## Keep the supported range wide
Constraints cover every supported version: `^8.2` PHP, `^12.0||^13.0` for Laravel components, `^10.0||^11.0` for Testbench (its versions for Laravel 12 and 13), `^3.0||^4.0||^5.0` for Pest. Dropping a version is a breaking change for a major release. Use caret constraints; never `*` or `dev-main` (the getID3 `2.0.x-dev` constraint is the one exception, until it tags a release).

## Dashboard packages are build-time only
Everything in `package.json` is a `devDependency`: it is bundled into `dist/` and never installed by users. A new one must earn its weight in the bundle (`.ai/rules/frontend.md`). TypeScript stays on 6.x until `vue-tsc` supports 7: its `@volar/typescript` dependency requires `typescript<7`, and the type check crashes on 7.

## Dev-only files stay out of the archive
Files only maintainers need (`workbench/`, `tests/`, `docs/`, `.ai/`, build configs) are `export-ignore` in `.gitattributes`, so they never reach users' `vendor/`. Add a new dev-only file or folder there.
