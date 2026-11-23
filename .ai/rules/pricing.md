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
Every price is checked against the provider's live pricing page (the URLs in the provider's `sources` and the table in `docs/pricing.md`). Never write a price from model knowledge, a blog post or models.dev. A price feed (LiteLLM's cost map, OpenRouter's models API) only proposes a change; the page decides. When you can't open the page, say so and leave the price alone.

## The catalog is a JSON file, in cents
The catalog is `resources/pricing/catalog.json`, one section per provider (`currency`, `sources`, `tool_calls`, `models`), read by `PricingFile` into one `ProviderPricing` per provider and indexed by `PricingLookup`. There are no PHP pricing classes and no `costs.pricing` config: a user's own file (`costs.pricing_path`) overlays the bundled one by model id. Token prices are cents per 1M tokens (per 1M characters for character-billed models); per-unit models (images, seconds, minutes, searches) use `price_per_unit`. Use the provider's currency (`currency`; Scaleway is EUR). No database, no runtime fetching.

## Refresh the catalog with the sync, review the diff
`composer pricing-sync` runs `spectra:pricing-sync --path=resources/pricing/catalog.json`: it reads LiteLLM's cost map for every provider in the catalog except Scaleway, and writes the models whose price differs into the bundled file. The `pricing-sync` workflow runs it every 24 hours and opens the pricing pull request. Scaleway (a EUR catalog LiteLLM barely lists) has no feed: check its pricing page and edit its section by hand. Review every changed line against the provider's page before committing. Edit the file by hand only for what the feed can't give (Scaleway, a model page's billing details, `variants`, `tool_calls`, an alias that moved to another model).

## OpenRouter and Ollama have no catalog section
OpenRouter sends the cost of every request in the response (`usage.cost`), for hundreds of models, so its prices are not in the catalog: don't add an `openrouter` section or a feed for it. Its models are recorded under the id they were called with and are never reported as unpriced. Ollama runs locally and is deliberately $0.

## Prices come from feeds; nothing scrapes pages
`spectra:pricing-sync` ships with the package and needs no key. There is no Firecrawl integration and no page scraping any more: `spectra:pricing-scrape` is only an alias of the sync, kept so users' scripts keep working, and its `--all` and `--url` options do nothing. Don't bring page scraping back; a provider no feed covers is edited by hand. Written to a user's file, the sync saves only the models that differ from the bundled catalog, so the file keeps following the catalog on upgrade, and each run replaces the sections of the providers it synced. The sync itself is the `SyncPricingFile` action, run by the command only. The dashboard never reads a feed and never writes a pricing file: its **Recalculate costs** button (`RecalculateCosts` action, `manageSpectra`; `RecalculateCostsJob` when `queue.enabled` is on, else during the request, and the card says which) reads the catalog and rewrites stored requests' costs, nothing else.

## Recalculating never guesses
`RecalculateCosts` prices a stored request again only from what its row holds. A request keeps its recorded cost when the provider reported it (`ReportsRequestCost`), the catalog has no price, the row's currency differs, or the cost depends on something the row doesn't keep (audio/image token split, 1-hour cache writes, megapixels, searches, a provider's cost multiplier); the last group is priced only when it was recorded at $0. A new cost input that isn't a column on `spectra_requests` must be added to those checks, and a new provider that reports its own cost must implement `ReportsRequestCost`.

## Feed prices stay guarded
Feed rows (`Support/Pricing/Feeds`) reach the catalog through `FeedPriceMapper`, which skips and reports a row that can't be right: a tier or price field the catalog doesn't use, an outlier (ten times ours), a price that is another column of the same row, an impossible price, a wrong unit. `PricingSync` picks one feed id per catalog model (the model's own id, then an alias, then a dated snapshot) and reports an alias the feed prices differently instead of guessing which model it belongs to. A new model is added only when models.dev lists it at the same price, never from one source alone, and never when it is billed per unit. To keep our price where a feed is wrong, restore it and run `composer pricing-sync -- --decline`, then commit `resources/pricing/declined.json`; never loosen a check to make a feed row pass. That file ships with the package, so users' syncs leave the same changes out. A provider is added to a feed only when the feed covers its models in the catalog's currency: measure the coverage first.

## Feed-only providers say so in NOTES.md
Together AI, Fireworks AI, DeepInfra and Cerebras were added from the feeds: their sections hold the models LiteLLM and models.dev list at the same price, and `resources/pricing/NOTES.md` says the prices have not been checked against the provider's page. When you check one, fix what differs and drop that sentence from its notes. A new OpenAI-compatible provider follows the same path: a `Provider` class with its host and a chat handler, its slug in `LiteLlmFeed::PROVIDERS` and `ModelsDevPrices::PROVIDERS`, an empty catalog section with `sources`, then `composer pricing-sync -- {slug}`. Check the provider's API reference first for its host, path and usage fields, and leave out a provider whose billing the catalog can't express (DeepSeek's peak and off-peak prices, Perplexity's per-request search fees).

## Every model has a billable standard tier
Each model needs a `standard` row, which the calculator falls back to. Per-unit models need a non-zero `price_per_unit` on it, and token models numeric `input_price` and `output_price`. A model the provider gives away (OpenRouter's `:free` variants) is priced 0 on purpose, with a note saying so. `PricingCatalogInvariantsTest` checks this; never loosen it to make a model pass.

## Key the undated id; list the rest as aliases
Key the model by its undated id (`gpt-4o`, `claude-opus-4-8`): lookup strips trailing `-YYYYMMDD` / `-YYYY-MM-DD` dates and OpenRouter routing suffixes on its own. List other ids that bill the same (`-latest`, provider-prefixed, renamed) in `aliases`. A dated snapshot gets its own entry only when its price differs.

## Never delete a model
When a provider shuts a model down, keep the entry and set `retired_on`, so stored requests keep their price. When a price changes on a known date, add a second row with `since` instead of editing the old one.

## Tiers are PricingTier values
Use the `PricingTier` values (`batch`, `flex`, `standard`, `priority`, `fast`, `ultrafast`, `hd`). A tier that isn't in the enum is skipped with a warning, so add the case before using it (`.ai/rules/enums.md`).

## Explain the quirks in NOTES.md
`resources/pricing/NOTES.md` has a section per provider that says when its prices were last checked by hand and every provider-wide billing rule the numbers don't show: cache discounts and whether they stack with batch, long-context thresholds, minimum billed lengths, free tiers, approximations (ElevenLabs is per-character pay-as-you-go). Logic a price can't express lives next to its data: fal.ai video prices per resolution are the model's `variants`, read by the fal.ai provider's cost multiplier; tool call surcharges are the provider's `tool_calls`. The catalog itself carries no `notes`: it ships to every app and no code reads them. `NOTES.md` is export-ignored.

## Record the review
After checking prices, update "Prices last reviewed" in `docs/pricing.md` and add every changed price to `CHANGELOG.md` under "Fixed" (wrong price) or "Added" (new model), with the old and new numbers when a price changed.

## Drift findings are leads, not prices
`composer pricing-drift` compares the catalog with models.dev and is maintainer-only (it lives in `workbench/`). Check each finding against the provider's page. When a difference is intentional, record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. Don't replace the catalog with models.dev data.

## The lookup is cached
`PricingCatalog::lookup()` keeps the `PricingLookup` in the cache store (`costs.cache`, on by default), keyed by a fingerprint of both files' path, size and mtime; `spectra:pricing-sync` flushes it after writing. A cache that fails logs once and falls back to the files. Anything that changes what the lookup holds must change its `__serialize()` shape and bump `PricingCatalog::CACHE_VERSION`.
