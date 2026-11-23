# Pricing

Spectra prices every tracked request from a pricing catalog: a JSON file bundled with the package that lists each provider's models, their pricing tiers and capabilities. Spectra reads it once per process, keeps the index in your cache, and looks prices up in memory at runtime, with no database queries.

<a name="how-it-works"></a>
## How It Works

The catalog is the package's `resources/pricing/catalog.json`. It is kept current from public price feeds and the providers' pricing pages, and every change is reviewed by hand (see [Keeping Prices Current](#keeping-prices-current)). The file is the whole catalog: every built-in provider, with its currency, pricing pages, separately billed tool calls and models.

::: info Prices are checked every 24 hours
Every day, Spectra's repository checks its prices against public price feeds. When a price changes or a new model appears, a maintainer checks the change against the provider's page and releases it as a patch version (for example 4.0.0 → 4.0.1). Run `composer update spectra-php/laravel-spectra` to get the latest prices. See [Scheduled Price Updates](#scheduled-price-updates).
:::

To bill with prices of your own, point `SPECTRA_PRICING_PATH` at a [file in the same format](#your-own-prices). A model in your file replaces the bundled model with the same id, and every other model keeps its bundled price. [`spectra:pricing-sync`](#syncing-prices) writes that file for you from public price feeds.

All prices are in **cents** of the provider's currency (US cents unless the provider's section says otherwise, see [Currency](#currency)). Token prices are cents per 1M tokens. Unit prices are cents per unit.

<a name="the-pricing-file"></a>
<a name="model-definition-api"></a>
<a name="scraped-prices-file-format"></a>
## The Pricing File

The bundled catalog and your own file share one format: JSON, with one section per provider and the models keyed by id.

```json
{
    "generated_at": "2026-11-05T12:00:00+00:00",
    "providers": {
        "openai": {
            "currency": "USD",
            "sources": ["https://developers.openai.com/api/docs/pricing"],
            "tool_calls": { "web_search_call": 1.0, "file_search_call": 0.25 },
            "models": {
                "gpt-4o": {
                    "display_name": "GPT-4o",
                    "type": "text",
                    "pricing_unit": "tokens",
                    "capabilities": ["text"],
                    "aliases": ["gpt-4o-2024-08-06"],
                    "tiers": [
                        { "tier": "standard", "input_price": 250, "output_price": 1000, "cached_input_price": 125 },
                        { "tier": "batch", "input_price": 125, "output_price": 500 }
                    ]
                }
            }
        }
    }
}
```

<a name="provider-fields"></a>
### Provider Fields

| Field | Default | Description |
| --- | --- | --- |
| `currency` | `USD` (in your file: the bundled provider's) | The ISO 4217 code of the currency the prices are written in. See [Currency](#currency). |
| `sources` | none | The provider's pricing pages, where its prices can be checked. |
| `tool_calls` | none | Cents per separately billed tool call, keyed by call type. See [Tool Call Pricing](#tool-call-pricing). |
| `notes` | none | Notes of your own on a provider in your file, such as why a price is what it is. Spectra doesn't read them, and [`spectra:pricing-sync`](#syncing-prices) keeps them. The bundled catalog has none. |
| `scraped_at` | none | In files `spectra:pricing-scrape` wrote before 4.3: when it last read the provider's pages. Not used. |
| `models` | required | The models, keyed by the id used in API requests. |

<a name="model-properties"></a>
### Model Fields {#model-properties}

| Field | Default | Description |
| --- | --- | --- |
| `display_name` | none | Human-readable name shown in the dashboard |
| `type` | `text` | Model type: `text`, `embedding`, `image`, `audio`, `video`. In your file, an entry for a bundled model keeps that model's type. |
| `pricing_unit` | `tokens` | What the price is charged against. See [Pricing Units](#pricing-units). In your file, an entry for a bundled model keeps that model's unit: one that names a different unit is skipped, because the model's handler produces metrics in its own unit and the entry would bill every call at $0. |
| `capabilities` | `[]` | Any of `text`, `images`, `video`, `audio`: what the model generates. The requests filter narrows by them. |
| `aliases` | `[]` | Other model ids that bill exactly like this model: `-latest` aliases, dated snapshots, provider-prefixed ids, or old ids that now redirect |
| `retired_on` | none | The date (`Y-m-d`) the provider shut the model down. The model stays in the catalog, so older requests keep their prices. |
| `variants` | none | Prices of the model's options, for the provider's cost multiplier: a fal.ai video model lists each resolution's price per second with and without audio (`{"720p": {"audio": 40, "silent": 20}}`). |
| `tiers` | required | One row per tier, prompt-size threshold and start date. See [Tier Rows](#tier-pricing). |

<a name="pricing-units"></a>
### Pricing Units

| Unit | `price_per_unit` is charged per |
| --- | --- |
| `tokens` | Not used. Token models use `input_price`, `output_price` and the other token prices below. |
| `minute` | Minute of audio |
| `second` | Second of audio or video |
| `characters` | 1M characters of input text |
| `image` | Generated image |
| `megapixel` | Megapixel of generated output |
| `video` | Generated video |
| `search` | Search unit (for example, a rerank search) |
| `page` | Document page (for example, OCR) |
| `request` | Successful request (a flat price per call) |

<a name="tier-pricing"></a>
### Tier Rows {#tier-pricing}

Each row of `tiers` holds the prices for one pricing tier. Tier names: `standard`, `batch`, `flex`, `priority`, `fast`, `ultrafast`, and `hd`. Every model needs a `standard` row: a request for a tier the model does not list is billed at its `standard` price.

**Token prices** (cents per 1M tokens):

| Field | Description |
| --- | --- |
| `input_price` | Uncached input tokens |
| `output_price` | Output tokens, including reasoning tokens |
| `cached_input_price` | Input tokens read from the prompt cache. Falls back to `input_price`. |
| `cache_write_5m_price` | Input tokens written to the prompt cache (Anthropic 5-minute writes, OpenAI 30-minute writes). Falls back to `input_price`. |
| `cache_write_1h_price` | Input tokens written to the prompt cache with a longer lifetime (Anthropic 1-hour writes, OpenAI 12-hour writes). Falls back to `cache_write_5m_price`, then `input_price`. |
| `audio_input_price` | Audio input tokens. Falls back to `input_price`. |
| `audio_output_price` | Audio output tokens. Falls back to `output_price`. |
| `image_input_price` | Image input tokens. Falls back to `input_price`. |
| `image_output_price` | Image output tokens. Falls back to `output_price`. |

**Unit price** (cents):

| Field | Description |
| --- | --- |
| `price_per_unit` | Cost per unit of the model's pricing unit |

**Conditions** (optional):

| Field | Description |
| --- | --- |
| `above_input_tokens` | Use these prices only when the prompt is larger than this many tokens. See [Long-Context Prices](#long-context-prices). |
| `since` | Use these prices only for requests made on or after this date (`Y-m-d`). See [Prices That Change on a Date](#dated-prices). |

A missing price works like a missing parameter above: it falls back, or counts as 0 for `input_price` and `output_price`.

<a name="examples"></a>
## Examples

**Token-based text model with several tiers:**

```json
"gpt-4o": {
    "display_name": "GPT-4o",
    "capabilities": ["text"],
    "tiers": [
        { "tier": "standard", "input_price": 250, "output_price": 1000, "cached_input_price": 125 },
        { "tier": "batch", "input_price": 125, "output_price": 500 },
        { "tier": "priority", "input_price": 425, "output_price": 1700, "cached_input_price": 212.5 }
    ]
}
```

**Token-based image model with image token prices:**

```json
"gpt-image-1.5": {
    "display_name": "GPT Image 1.5",
    "type": "image",
    "capabilities": ["images"],
    "tiers": [
        { "tier": "standard", "input_price": 500, "output_price": 1000, "cached_input_price": 125, "image_input_price": 800, "image_output_price": 3200 }
    ]
}
```

**Per-image model:**

```json
"dall-e-3": {
    "display_name": "DALL-E 3",
    "type": "image",
    "pricing_unit": "image",
    "capabilities": ["images"],
    "tiers": [
        { "tier": "standard", "price_per_unit": 4 },
        { "tier": "hd", "price_per_unit": 8 }
    ]
}
```

**Per-megapixel image model:**

```json
"fal-ai/flux/dev": {
    "display_name": "FLUX.1 Dev",
    "type": "image",
    "pricing_unit": "megapixel",
    "capabilities": ["images"],
    "tiers": [{ "tier": "standard", "price_per_unit": 2.5 }]
}
```

**Audio transcription (per minute):**

```json
"whisper-1": {
    "display_name": "Whisper",
    "type": "audio",
    "pricing_unit": "minute",
    "capabilities": ["text"],
    "tiers": [{ "tier": "standard", "price_per_unit": 0.6 }]
}
```

**Video (per second):**

```json
"sora-2": {
    "display_name": "Sora 2",
    "type": "video",
    "pricing_unit": "second",
    "capabilities": ["video"],
    "tiers": [{ "tier": "standard", "price_per_unit": 10 }]
}
```

**OCR (per page):**

```json
"mistral-ocr-latest": {
    "display_name": "Mistral OCR 4.1",
    "pricing_unit": "page",
    "tiers": [
        { "tier": "standard", "price_per_unit": 0.4 },
        { "tier": "batch", "price_per_unit": 0.2 }
    ]
}
```

**Anthropic model with cache write prices, aliases, and a retirement date:**

```json
"claude-sonnet-4": {
    "display_name": "Claude Sonnet 4",
    "aliases": ["claude-sonnet-4-20250514", "claude-sonnet-4-0"],
    "retired_on": "2026-06-15",
    "capabilities": ["text"],
    "tiers": [
        { "tier": "standard", "input_price": 300, "output_price": 1500, "cached_input_price": 30, "cache_write_5m_price": 375, "cache_write_1h_price": 600 }
    ]
}
```

<a name="long-context-prices"></a>
## Long-Context Prices

Some models cost more when the prompt is long. List the same tier twice: once with the normal prices, and once with `above_input_tokens`:

```json
"claude-haiku-5-5": {
    "display_name": "Claude Haiku 5.5",
    "capabilities": ["text"],
    "tiers": [
        { "tier": "standard", "input_price": 10, "output_price": 50, "cached_input_price": 1 },
        { "tier": "standard", "input_price": 50, "output_price": 250, "cached_input_price": 5, "above_input_tokens": 100000 }
    ]
}
```

The prompt size is the full prompt: uncached tokens, cache reads, and cache writes. When the prompt is larger than `above_input_tokens`, the whole request (input and output) uses that row. When several rows match, the one with the highest threshold wins.

<a name="dated-prices"></a>
## Prices That Change on a Date {#dated-prices}

When a provider announces a new price for a future date, add a second row with `since`:

```json
"my-model": {
    "display_name": "My Model",
    "capabilities": ["text"],
    "tiers": [
        { "tier": "standard", "input_price": 100, "output_price": 400 },
        { "tier": "standard", "input_price": 80, "output_price": 320, "since": "2026-12-01" }
    ]
}
```

Spectra uses the date the request was made. Requests before 2026-12-01 use the first row, and requests on or after that date use the second row. When rows have both `since` and `above_input_tokens`, Spectra first picks the newest `since` date that has started, then the highest prompt-size threshold the prompt is above.

Dates must use the `Y-m-d` format. A row with any other format is skipped with a warning, and the model with it.

<a name="model-lookup"></a>
## How Spectra Finds a Model {#model-lookup}

Spectra looks up the `provider` and model id in this order:

1. The exact model id.
2. A model that lists the id in `aliases`.
3. The id without a trailing snapshot date. For example, `claude-opus-4-8-20260528` matches `claude-opus-4-8`, and `gpt-4o-2024-08-06` matches `gpt-4o`.
4. The id without an OpenRouter routing suffix (`:nitro`, `:floor`, `:online`, `:exacto`, `:thinking`, `:extended`, `:beta`). For example, `anthropic/claude-sonnet-4.5:nitro` matches `anthropic/claude-sonnet-4.5`.

The shorter ids in steps 3 and 4 are also checked against aliases. The `:free` suffix is never removed, so a free model never picks up the price of the paid model.

A catalog entry always wins over an alias with the same id. Because the exact id is checked first, a dated snapshot with its own entry (for example, `gpt-4o-2024-05-13`) keeps its own price.

List models under their **undated** id, and the dated snapshots resolve on their own. Names that differ in any other way do not match, and the request is billed **0**.

<a name="snapshot-first"></a>
### The Served Model Is Priced First {#snapshot-first}

The model in the response can differ from the model you asked for. This happens with aliases, routers, and retired models that redirect to a newer model. Spectra prices the model the provider actually served (stored in the `snapshot` column) when the catalog knows it. If the catalog does not know it, Spectra prices the model you asked for.

<a name="reported-costs"></a>
## Reported Costs

Some providers send the real cost of a request in the response:

- **OpenRouter**: `usage.cost`
- **xAI**: `cost_in_usd_ticks`
- **DeepInfra**: `usage.estimated_cost`

When a response has this cost, Spectra stores it as is, instead of the catalog price. It is converted from US dollars to your [reporting currency](#currency). For xAI and DeepInfra, the catalog price is the fallback for a response without a cost. OpenRouter has no catalog, so a response without a cost is recorded at $0.

<a name="currency"></a>
## Currency

Each provider section says which currency its prices use. The default is `USD`:

```json
"scaleway": {
    "currency": "EUR",
    "models": { }
}
```

Spectra stores costs in cents of the currency set in `spectra.costs.currency` (default `USD`). When a provider's prices are in another currency, Spectra converts them with the rates in `spectra.costs.exchange_rates`. The bundled Scaleway prices are in EUR.

A rate says how many units of your reporting currency one unit of the other currency is worth:

```php
// config/spectra.php: report in USD
'costs' => [
    'currency' => 'USD',
    'exchange_rates' => [
        'EUR' => 1.17,
    ],
],
```

If you report in EUR, add a rate for USD instead (`'USD' => 0.86`). It is used for every USD provider and for costs that providers report in US dollars.

When a rate is missing, Spectra counts the prices 1:1 and logs a warning once per process. Spectra does not fetch exchange rates. Update them from time to time.

<a name="your-own-prices"></a>
<a name="customizing-pricing"></a>
<a name="adding-a-new-provider"></a>
<a name="scraped-prices"></a>
## Your Own Prices

To change a price, add a model the catalog doesn't have yet (a fine-tune, a new release), or price a provider of your own, write a pricing file and point Spectra at it in your `.env` file:

```ini
SPECTRA_PRICING_PATH=resources/spectra/pricing.json
```

A relative path is relative to your app's root. Spectra then bills with your file first: each model in it replaces the bundled model with the same id, and every model your file doesn't list keeps its bundled price. Because the bundled catalog fills the gaps, your file only needs the models you want to change. Commit it, so every environment bills the same prices.

```json
{
    "providers": {
        "openai": {
            "models": {
                "ft:gpt-4o:my-org:custom-suffix": {
                    "display_name": "My Fine-tune",
                    "capabilities": ["text"],
                    "tiers": [{ "tier": "standard", "input_price": 300, "output_price": 1200 }]
                }
            }
        },
        "my-provider": {
            "models": {
                "my-model": {
                    "display_name": "My Model",
                    "capabilities": ["text"],
                    "tiers": [{ "tier": "standard", "input_price": 100, "output_price": 400 }]
                }
            }
        }
    }
}
```

You can write the file by hand, or let [`spectra:pricing-sync`](#syncing-prices) fill it from public price feeds. The format is [the one above](#the-pricing-file), with these rules:

- A model in your file replaces the **whole** bundled model, so list every row it needs: other tiers, [long-context prices](#long-context-prices) and [dated prices](#dated-prices). `spectra:pricing-sync` copies those rows from the bundled model for you.
- An entry for a bundled model keeps that model's `type` and `pricing_unit` when it leaves them out. One that names a different unit is skipped.
- A provider's `currency` defaults to the bundled provider's. A provider whose currency differs from the bundled one is skipped, because its prices would be converted with the wrong rate. A provider the bundled catalog doesn't have (`my-provider` above) is added as is.

::: info Problems never stop cost tracking
If the file is missing or isn't valid JSON, Spectra logs a warning and bills with the bundled catalog. A model that can't be read (an unknown tier, a price that isn't a number, a bad date, a pricing unit that differs from the bundled model's, no `standard` tier) is skipped with a warning, and the rest of the file still loads.
:::

<a name="tool-call-pricing"></a>
## Tool Call Pricing

Some providers charge separately for built-in tool calls (web search, file search, code interpreter). The provider's `tool_calls` lists these fees in cents per call:

```json
"openai": {
    "tool_calls": {
        "web_search_call": 1.0,
        "file_search_call": 0.25,
        "code_interpreter_call": 3.0
    },
    "models": { }
}
```

The keys match the tool call types Spectra counts in the response (for example, OpenAI's `web_search_call` output items) or the keys a handler reports in `Metrics::$toolCalls` (for example, Anthropic's `web_search_requests`). Tool call fees are added to the total cost of the request. They are converted with the provider's currency, like the other prices. In your own file, a provider's `tool_calls` replaces the bundled list for that provider.

<a name="syncing-prices"></a>
## Syncing Prices from Feeds {#syncing-prices}

`spectra:pricing-sync` reads LiteLLM's cost map, a public price feed, and saves the prices that differ from the bundled catalog, and the models it doesn't have, to [your pricing file](#your-own-prices). Use it to pick up a price change or a new model before the next Spectra release. It needs no key and costs nothing to run.

Tell Spectra where the file lives in your `.env` file:

```ini
SPECTRA_PRICING_PATH=resources/spectra/pricing.json
```

Then run the command, review the changes it prints, and commit the file:

```shell
php artisan spectra:pricing-sync              # every provider the feed covers
php artisan spectra:pricing-sync openai anthropic
php artisan spectra:pricing-sync --dry-run    # show the changes, write nothing
```

Only the command calls the feed. Tracking reads the saved file and never makes a network call.

<a name="price-feeds"></a>
### Where the Prices Come From {#price-feeds}

The feed publishes prices as data, so nothing has to be read off a web page:

| Providers | Feed |
| --- | --- |
| OpenAI, Anthropic, Google, xAI, Mistral, Cohere, Groq, ElevenLabs, fal.ai, Replicate, Together AI, Fireworks AI, DeepInfra, Cerebras | [LiteLLM's cost map](https://github.com/BerriAI/litellm/blob/main/model_prices_and_context_window.json), a JSON file LiteLLM's team and contributors update several times a day |
| OpenRouter | Not in the catalog. OpenRouter sends the cost of every request in the response, and Spectra records that cost, whatever the model. |
| Scaleway | None: LiteLLM lists only a couple of its models. Its prices come with each Spectra release. |

LiteLLM is not the provider, so its file can be wrong or late, and it doesn't list every model: a model it leaves out keeps its bundled price. Review the changes the command prints before you deploy the file, as you would review any other change.

<a name="sync-how-it-works"></a>
### How It Works {#sync-how-it-works}

1. The command downloads the feed and keeps the entries of the providers it syncs. LiteLLM lists the same model on many hosts (Azure, Bedrock, Vertex); only the provider's own API counts.
2. Each model in the bundled catalog is matched to one feed entry: its own id first, then one of its `aliases`, then a dated snapshot. Prices are converted to cents, and a feed price that is ours after rounding is not a change.
3. Only **changed** and **new** models are saved to your file. A model whose prices match the bundled catalog stays out of it, so it keeps following the bundled catalog when you upgrade Spectra. The file is written with one model per line, so a diff shows which models changed.
4. Each run replaces the sections of the providers it synced. A provider whose prices all match is removed from your file, and a provider whose feed couldn't be loaded keeps its current entries. Providers no feed covers are never touched, so prices you wrote for them by hand stay.

A feed is not the provider, so the command checks every price and prints why it left one out:

- **No outliers**: a price 10 times higher or lower than the catalog's.
- **No impossible prices**: cached input above input, output below input, or long-context prices below the regular ones.
- **No column mix-ups**: a feed price that equals another price of the same catalog row skips the row.
- **Only what billing can use**: a tier or price field the provider's catalog doesn't use is ignored, and a model billed per unit keeps its unit.
- **A new model needs two sources**: it is added only when [models.dev](https://models.dev) lists it at the same price. A new model billed per image, minute or character is never added from a feed.
- **Declined changes stay out**: a change Spectra's maintainers reviewed against the provider's page and declined is not applied in your app either. The list ships with the package.

When the feed prices one of a model's `aliases` differently from the model itself, the command says so. That usually means the provider has pointed the alias at a newer model.

Because each run replaces a synced provider's section, don't keep hand-written prices for a model of a synced provider in the same file: name the providers to sync and leave that one out.

<a name="sync-options"></a>
### Options {#sync-options}

| Option | Description |
| --- | --- |
| `provider*` | The providers to sync. Default: every provider the feed covers. |
| `--path=` | Write this file instead of `spectra.costs.pricing_path` (default: `resources/spectra/pricing.json`). |
| `--dry-run` | Print the changes without writing the file. |

The feed URLs are in `spectra.costs.feeds` ([configuration](/configuration#costs)). Change one to read a mirror, or a fixed commit of LiteLLM's file.

<a name="scraping-prices"></a>
<a name="scraped-providers"></a>
<a name="firecrawl-how-it-works"></a>
<a name="firecrawl-options"></a>
<a name="pricing-pages"></a>
### The `spectra:pricing-scrape` Alias {#scraping-prices}

`spectra:pricing-scrape` is an alias of `spectra:pricing-sync`, so a script or deploy step that calls it keeps working. It reads the price feeds and needs no key. Its `--all` and `--url` options are accepted and do nothing: every provider a feed covers is synced unless you name some, and no pricing page is read.

<a name="recalculating-costs"></a>
## Recalculating Stored Costs {#recalculating-costs}

A request's cost is calculated once, when the request is recorded, from the prices the catalog had then. A later change to the catalog doesn't reach the requests already stored: a model that had no price stays at $0, and a price that was wrong stays wrong.

**Recalculating costs** prices the stored requests again from the catalog Spectra bills with now (the bundled catalog plus [your own prices](#your-own-prices)). Each request is priced from the usage stored with it, on the tier it was recorded with, at the price that applied [on its date](#dated-prices). Then the daily stats of the stored days are rebuilt, so the dashboard's totals follow. It reads the catalog and writes only the database: no pricing file is changed and no price feed is read.

Start it from the dashboard: the Maintenance screen's Prices card has a **Recalculate costs** button. It asks you to confirm, because the costs it replaces can't be brought back. Everyone who can open the dashboard sees the button; it works for users the `manageSpectra` gate allows, and the card tells the others so. When it is done, the card says how many requests changed and by how much the total moved.

Where it runs follows [`spectra.queue.enabled`](/configuration#queue), the setting that decides where Spectra saves requests, and the card says which applies before you confirm:

- **Queue on:** it runs as a queued job on Spectra's queue connection and queue, one at a time. It needs a queue worker; the card shows it as queued until the job reports back.
- **Queue off:** it runs during the request. With many stored requests this can take longer than your server's request timeout, so turn the queue on for a large table.

A request keeps the cost it was recorded with when the catalog can't say better:

- **The provider reported the cost.** OpenRouter, xAI and DeepInfra send what a request cost in the response, and Spectra stored that figure. See [Reported Costs](#reported-costs).
- **The catalog has no price for its model.** It stays at $0 until the model is in the catalog.
- **It was recorded in another reporting currency** than the one `spectra.costs.currency` names now.
- **Its cost was made from something the request row doesn't keep.** The row holds the token totals, cached tokens, cache writes, images, videos, pages, seconds and characters. It doesn't hold how many of the tokens were audio or image tokens, how many cache writes were kept for an hour, megapixels, searches, or a surcharge read from the request (Anthropic's US-only inference, a fal.ai video's resolution and length). A request on a model or provider that bills one of these keeps its cost, with one exception: when it was recorded at $0, it is priced from the usage the row does keep, which is closer than $0.

Requests that were pruned are not recalculated, and the daily stats of pruned days keep the costs they have.

The catalog is read as it is now. A request recorded under an alias, such as a `-latest` id, is priced as the model that alias points to today, even when it pointed to another model on the request's date. Check an alias that has moved before you recalculate.

<a name="caching"></a>
## Caching

The pricing files are read and indexed once per process. With the cache on (the default), the index is kept in your cache store instead, so a PHP-FPM request doesn't parse the files again. The cached index is rebuilt when a pricing file changes, when the package is updated, and after `spectra:pricing-scrape` writes a file.

```ini
SPECTRA_PRICING_CACHE=true          # default
SPECTRA_PRICING_CACHE_STORE=redis   # default: your default cache store
SPECTRA_PRICING_CACHE_TTL=86400     # seconds; 0 keeps it until a file changes
```

A cache store that can't be read or written never stops cost tracking: Spectra logs a warning once per process and builds the index from the files. Warnings about entries the files can't load are logged when the index is built, not on every request.

A long-running process loads the index once and keeps it in memory, so queue workers, Horizon and Octane keep using the old prices until they restart. After you change a pricing file, restart them the way you do after a deploy (`php artisan queue:restart`, `php artisan horizon:terminate` or `php artisan octane:reload`).

<a name="keeping-prices-current"></a>
## Keeping Prices Current

Provider prices change, and new models ship often. The bundled catalog follows them with a daily check, and you get the updates with `composer update`. The catalog's `generated_at` says when it was last updated.

<a name="daily-price-updates"></a>
<a name="scheduled-price-updates"></a>
### Scheduled Price Updates

**Every 24 hours**, a GitHub Actions workflow in Spectra's repository (`pricing-sync`) checks the bundled catalog against the [price feed](#price-feeds):

1. At 05:00 UTC, it runs [`spectra:pricing-sync`](#syncing-prices) against the bundled catalog. That reads LiteLLM's cost map for every provider in the catalog except Scaleway. No web page is scraped.
2. If no price changed and no new model appeared, it stops. Nothing is released.
3. Otherwise it runs the test suite with the new catalog and opens one pull request with the changes. A day's run replaces the previous day's changes in that same pull request, so there is never more than one open.
4. A maintainer checks each change against the provider's pricing page and merges the pull request. A feed is not the provider's word, so nothing is merged without that review.
5. Merging releases the next **patch version** (4.0.0 → 4.0.1) and adds it to the changelog (the `pricing-release` workflow).

Scaleway, which no feed covers, is not part of the daily check. A maintainer refreshes it [by hand](#refreshing-by-hand).

To pick up new prices in your app:

```shell
composer update spectra-php/laravel-spectra
```

Then restart your queue workers, Horizon or Octane, which [keep the old prices in memory](#caching) until they restart.

A price change can take a day to be found and a little longer to be reviewed. To bill a new price sooner, put it in [your own file](#your-own-prices), or run [`spectra:pricing-sync`](#syncing-prices) yourself.

<a name="refreshing-by-hand"></a>
### Refreshing by Hand

Maintainers can run the same refresh locally in the package repository:

```shell
composer pricing-sync
```

`composer pricing-sync` runs `spectra:pricing-sync --path=resources/pricing/catalog.json`. Pointed at the bundled catalog, the command writes the models whose feed price differs into it and leaves every other model as it is. Add `-- --dry-run` to see the changes without writing them, or name providers to sync only those (`composer pricing-sync -- openai --dry-run`).

Scaleway has no feed. Check its prices on its pricing page and edit its section of the catalog by hand.

Review the diff against the provider's pricing page before committing it. When a feed is wrong about a price, keep ours: restore it in the catalog, run `composer pricing-sync -- --decline` and commit `resources/pricing/declined.json`. The command records every change the feeds would still make as declined, and a declined price comes back for review only when the feed changes it. The file ships with the package, so `spectra:pricing-sync` leaves those changes out in every app too.

When the feed prices one of a model's `aliases` differently from the model itself, the sync says so. Move the alias to the entry it now bills as.

The pages are:

| Provider | Source |
| --- | --- |
| OpenAI | https://developers.openai.com/api/docs/pricing |
| Anthropic | https://platform.claude.com/docs/en/about-claude/pricing |
| Google (Gemini) | https://ai.google.dev/gemini-api/docs/pricing |
| xAI | https://docs.x.ai/developers/pricing |
| Mistral | https://docs.mistral.ai/inference/pricing |
| Cohere | https://cohere.com/pricing |
| Groq | https://console.groq.com/docs/models |
| Replicate | https://replicate.com/pricing |
| fal.ai | https://fal.ai/pricing |
| Scaleway | https://www.scaleway.com/en/pricing/model-as-a-service/ |
| ElevenLabs | https://elevenlabs.io/pricing/api |
| Together AI | https://www.together.ai/pricing |
| Fireworks AI | https://fireworks.ai/pricing |
| DeepInfra | https://deepinfra.com/pricing |
| Cerebras | https://www.cerebras.ai/pricing |

The prices of Together AI, Fireworks AI, DeepInfra and Cerebras come from the price feeds: a model is in the catalog when LiteLLM's cost map and models.dev list it at the same price. They have not been checked against these pages yet. A model the two sources disagree on is left out and reported as [unpriced](/costs#zero-cost-requests) until it is added.

<a name="drift-check-against-models-dev"></a>
### Drift Check Against models.dev

To spot prices that need a review, the repository has a maintainer command (not shipped with the package) that compares the catalog with the community-maintained [models.dev](https://models.dev) catalog:

```shell
vendor/bin/testbench spectra:pricing-drift
```

It lists token prices that differ (including long-context prices, and fallbacks such as a missing cached-input price billing at the input price) and models that models.dev prices but the catalog doesn't have. Per-unit models (images, audio, video), providers in another currency (Scaleway), and providers models.dev doesn't cover (Replicate, fal.ai, ElevenLabs) are skipped. OpenRouter isn't compared: it has no catalog, because it reports each request's cost itself.

models.dev is not a price source (the feed sync uses it only to confirm a new model): check every finding against the provider's pricing page. When a difference is intentional (models.dev is wrong, or it counts something differently), record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. A recorded difference is reported again if models.dev changes that price. The `pricing-drift` workflow runs the check every Monday and keeps one open issue with the unreviewed findings.

When a provider renames a model, keep the old id in `aliases`. When a provider shuts a model down, keep the entry and set `retired_on`, so older requests keep their prices. The `PricingCatalogInvariantsTest` checks the shape of the catalog.

::: info Providers without token pricing
**Ollama** runs models locally, so its requests are deliberately tracked at **$0**. The catalog has no Ollama section, so its models are never reported as unpriced.

**OpenRouter** is not in the catalog either. It sends the cost of every request in the response (`usage.cost`), and Spectra records that cost, for every model OpenRouter offers. An OpenRouter model shows under the id you called it with, such as `anthropic/claude-sonnet-4.5`. To price a response that carries no cost, add an `openrouter` section to [your own file](#your-own-prices).

**Google Imagen** is shut down: Google removed the Imagen models from the Gemini API and from its pricing page. Spectra still records a `:generateImages` call, with its images, but has no price for it, so it is reported as [unpriced](/costs#zero-cost-requests). Use the Gemini image models (`gemini-2.5-flash-image`, `gemini-3.1-flash-image`, `gemini-3-pro-image`), which are priced.

**ElevenLabs** bills subscription plans in credits, not per request. The bundled prices are the published pay-as-you-go per-character API rates, as an approximation. If your plan's effective rate differs, set the prices in [your own file](#your-own-prices).
:::
