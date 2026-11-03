---
paths:
  - 'src/Pricing/**'
  - 'src/Support/Pricing/**'
  - 'docs/pricing.md'
  - 'workbench/app/Pricing/**'
  - 'workbench/pricing-drift-baseline.json'
---

# Pricing

## Prices come from the provider's page, never from memory
Every price is checked against the provider's live pricing page (the URLs in the class's `sources()` and the table in `docs/pricing.md`). Never write a price from model knowledge, a blog post or models.dev. When you can't open the page, say so and leave the price alone.

## The catalog is PHP, in cents
Each provider has a `{Provider}Pricing` class extending `ProviderPricing`, defining models in `define()` with the fluent `ModelDefinition` builder. Token prices are cents per 1M tokens (per 1M characters for character-billed models); per-unit models (images, seconds, minutes, searches) use the unit price. Use the catalog's currency (`currency()`; Scaleway is EUR). No database, no runtime fetching.

## Every model has a billable standard tier
Each model needs a `standard` tier, which the calculator falls back to. Per-unit models need a non-zero unit price on it, and token models numeric input and output prices. `PricingCatalogInvariantsTest` checks this; never loosen it to make a model pass.

## Define the undated id; list the rest as aliases
Name the model by its undated id (`gpt-4o`, `claude-opus-4-8`): lookup strips trailing `-YYYYMMDD` / `-YYYY-MM-DD` dates and OpenRouter routing suffixes on its own. List other ids that bill the same (`-latest`, provider-prefixed, renamed) in `aliases()`. A dated snapshot gets its own entry only when its price differs.

## Never delete a model
When a provider shuts a model down, keep the entry and add `retiredOn('Y-m-d')`, so stored requests keep their price. When a price changes on a known date, add a second `cost(…, since: 'Y-m-d')` row instead of editing the old one.

## Tiers are PricingTier values
Use the `PricingTier` values (`batch`, `flex`, `standard`, `priority`, `fast`, `ultrafast`, `hd`). A tier that isn't in the enum falls back to standard, so add the case before using it (`.ai/rules/enums.md`).

## Explain the quirks in the class docblock
Say where the prices come from and every billing rule the numbers don't show: cache discounts and whether they stack with batch, long-context thresholds, minimum billed lengths, free tiers, approximations (ElevenLabs is per-character pay-as-you-go). Ollama is deliberately $0 and has no class.

## Record the review
After checking prices, update "Prices last reviewed" in `docs/pricing.md` and add every changed price to `CHANGELOG.md` under "Fixed" (wrong price) or "Added" (new model), with the old and new numbers when a price changed.

## Drift findings are leads, not prices
`composer pricing-drift` compares the catalog with models.dev and is maintainer-only (it lives in `workbench/`). Check each finding against the provider's page. When a difference is intentional, record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. Don't replace the catalog with models.dev data.

## Scraped prices stay guarded
`spectra:pricing-scrape` writes only prices that pass every safeguard in `Support/Pricing/Scraping` (the price must appear next to the model in the page text; no mixed-up columns, impossible prices, outliers or wrong units). Never weaken a safeguard to let a page through; fix the mapping or skip the row and report it. Scraped prices are off by default and replace built-in models only by id.
