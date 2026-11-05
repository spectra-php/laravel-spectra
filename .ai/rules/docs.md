---
paths:
  - 'docs/**'
  - 'CHANGELOG.md'
  - 'README.md'
---

# Docs and changelog

## Ship the docs with the change
A change users can see (a config key, a provider, a model, a command option, a price, a column, an event property) updates its page in `docs/` in the same change. The docs are a VitePress site; every `##` and `###` heading on a page has an `<a name="…"></a>` anchor before it, because other pages and the README link to them. Never rename an existing anchor. The page's `#` title and `README.md` have none.

## Write for the user, plainly
Say what the feature does and how to use it, with a short PHP example when the feature has a PHP API. Use short sentences and the words the code uses. No internal class names a user never touches (a section for contributors may name the repository's own tests and tools), and no history ("previously…"). `CHANGELOG.md`, `docs/upgrade.md` and upgrade notes in `docs/migrations.md` exist to describe change, so old-versus-new is their job.

## Every release has a changelog entry
`CHANGELOG.md` has one `## vX.Y.Z - YYYY-MM-DD` section per release, with `#### Added`, `#### Changed`, `#### Fixed` and `#### Upgrading` as needed, in that order. Released sections stay as they shipped. Each bullet names the provider, area, command or method first ("Google: …", "`spectra:prune` …") and says what changed for the user. "Upgrading" says what the user must do, or "Nothing to do" and why.

## Version by semver
A new feature or model is a minor release; a fix or price correction is a patch. Anything that breaks a published config, a public method, an event, a table or a column waits for a major release and gets a section in `docs/upgrade.md`.

## Keep the pricing review date true
`docs/pricing.md` lists every provider's pricing page and the "Prices last reviewed" date. Change the date only after checking every provider's page.
