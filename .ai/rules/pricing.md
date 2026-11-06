---
paths:
  - 'src/Pricing/**'
  - 'src/Support/Pricing/**'
  - 'resources/pricing/**'
  - 'docs/pricing.md'
  - 'workbench/app/Pricing/**'
  - 'workbench/pricing-drift-baseline.json'
---

# Pricing

## Prices come from the provider's page, never from memory
Every price is checked against the provider's live pricing page (the URLs in the provider's `sources` and the table in `docs/pricing.md`). Never write a price from model knowledge, a blog post or models.dev. When you can't open the page, say so and leave the price alone.

## The catalog is a JSON file, in cents
The catalog is `resources/pricing/catalog.json`, one section per provider (`currency`, `notes`, `sources`, `tool_calls`, `models`), read by `PricingFile` into one `ProviderPricing` per provider and indexed by `PricingLookup`. There are no PHP pricing classes and no `costs.pricing` config: a user's own file (`costs.pricing_path`) overlays the bundled one by model id. Token prices are cents per 1M tokens (per 1M characters for character-billed models); per-unit models (images, seconds, minutes, searches) use `price_per_unit`. Use the provider's currency (`currency`; Scaleway is EUR). No database, no runtime fetching.

## Refresh the catalog with the scrape, review the diff
`composer pricing-scrape` runs `spectra:pricing-scrape --all` against the bundled file and rewrites each provider's section whole, keeping its `notes`. Review every changed line against the provider's page before committing. Edit the file by hand only for what the scrape can't read (OpenRouter's API, a model page's billing details, `variants`, `tool_calls`).

## Every model has a billable standard tier
Each model needs a `standard` row, which the calculator falls back to. Per-unit models need a non-zero `price_per_unit` on it, and token models numeric `input_price` and `output_price`. A model the provider gives away (OpenRouter's `:free` variants) is priced 0 on purpose, with a note saying so. `PricingCatalogInvariantsTest` checks this; never loosen it to make a model pass.

## Key the undated id; list the rest as aliases
Key the model by its undated id (`gpt-4o`, `claude-opus-4-8`): lookup strips trailing `-YYYYMMDD` / `-YYYY-MM-DD` dates and OpenRouter routing suffixes on its own. List other ids that bill the same (`-latest`, provider-prefixed, renamed) in `aliases`. A dated snapshot gets its own entry only when its price differs.

## Never delete a model
When a provider shuts a model down, keep the entry and set `retired_on`, so stored requests keep their price. When a price changes on a known date, add a second row with `since` instead of editing the old one.

## Tiers are PricingTier values
Use the `PricingTier` values (`batch`, `flex`, `standard`, `priority`, `fast`, `ultrafast`, `hd`). A tier that isn't in the enum is skipped with a warning, so add the case before using it (`.ai/rules/enums.md`).

## Explain the quirks in the provider's notes
A provider's `notes` say where the prices come from and every provider-wide billing rule the numbers don't show: cache discounts and whether they stack with batch, long-context thresholds, minimum billed lengths, free tiers, approximations (ElevenLabs is per-character pay-as-you-go). Logic a price can't express lives next to its data: fal.ai video prices per resolution are the model's `variants`, read by the fal.ai provider's cost multiplier; tool call surcharges are the provider's `tool_calls`. Ollama is deliberately $0 and has no section.

## Record the review
After checking prices, update "Prices last reviewed" in `docs/pricing.md` and add every changed price to `CHANGELOG.md` under "Fixed" (wrong price) or "Added" (new model), with the old and new numbers when a price changed.

## Drift findings are leads, not prices
`composer pricing-drift` compares the catalog with models.dev and is maintainer-only (it lives in `workbench/`). Check each finding against the provider's page. When a difference is intentional, record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. Don't replace the catalog with models.dev data.

## Scraped prices stay guarded
`spectra:pricing-scrape` writes only prices that pass every safeguard in `Support/Pricing/Scraping` (the price must appear next to the model in the page text; no mixed-up columns, impossible prices, outliers or wrong units). Never weaken a safeguard to let a page through; fix the mapping or skip the row and report it. Written to a user's file, it saves only the models that differ from the bundled catalog, so the file keeps following the catalog on upgrade.

## The lookup is cached
`PricingCatalog::lookup()` keeps the `PricingLookup` in the cache store (`costs.cache`, on by default), keyed by a fingerprint of both files' path, size and mtime; the scrape command flushes it after writing. A cache that fails logs once and falls back to the files. Anything that changes what the lookup holds must change its `__serialize()` shape and bump `PricingCatalog::CACHE_VERSION`.
