---
paths:
  - 'docs/**'
  - 'CHANGELOG.md'
  - 'README.md'
---

# Docs and changelog

## Ship the docs with the change
A change users can see (a config key, a provider, a model, a command option, a price, a column, an event property) updates its page in `docs/` in the same change. The docs are a VitePress site; keep the `<a name="…"></a>` anchor before each heading, because other pages and the README link to them.

## Write for the user, plainly
Say what the feature does and how to use it, with a short PHP example. Use short sentences and the words the code uses. No internal class names a user never touches, and no history ("previously…").

## Every release has a changelog entry
`CHANGELOG.md` has one `## vX.Y.Z - YYYY-MM-DD` section per release, with `#### Added`, `#### Fixed`, `#### Changed` and `#### Upgrading` as needed. Each bullet names the provider or area first ("Google: …") and says what changed for the user. "Upgrading" says what the user must do, or "Nothing to do" and why.

## Version by semver
A new feature or model is a minor release; a fix or price correction is a patch. Anything that breaks a published config, a public method, an event, a table or a column waits for a major release and gets a section in `docs/upgrade.md`.

## Keep the pricing review date true
`docs/pricing.md` lists every provider's pricing page and the "Prices last reviewed" date. Change the date only after checking every provider's page.
