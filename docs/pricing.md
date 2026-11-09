# Pricing

Spectra prices every tracked request from a pricing catalog: a JSON file bundled with the package that lists each provider's models, their pricing tiers and capabilities. Spectra reads it once per process, keeps the index in your cache, and looks prices up in memory at runtime, with no database queries.

<a name="how-it-works"></a>
## How It Works

The catalog is the package's `resources/pricing/catalog.json`. It is generated from the providers' pricing pages with [`spectra:pricing-scrape`](#scraping-prices), reviewed by hand and shipped with every release. The file is the whole catalog: every built-in provider, with its currency, pricing pages, separately billed tool calls and models.

To bill with prices of your own, point `SPECTRA_PRICING_PATH` at a [file in the same format](#your-own-prices). A model in your file replaces the bundled model with the same id, and every other model keeps its bundled price. [`spectra:pricing-scrape`](#scraping-prices) writes that file for you from the providers' pricing pages.

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
| `sources` | none | The pricing pages [`spectra:pricing-scrape`](#scraping-prices) reads for the provider. |
| `tool_calls` | none | Cents per separately billed tool call, keyed by call type. See [Tool Call Pricing](#tool-call-pricing). |
| `notes` | none | Why a price is what it is: where it was checked, what the provider bills that isn't modelled. The scrape keeps them. |
| `scraped_at` | none | When the scrape last read the provider's pages. Set by the scrape. |
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

**Token-based LLM with multiple tiers:**

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

When a response has this cost, Spectra stores it as is, instead of the catalog price. It is converted from US dollars to your [reporting currency](#currency). The catalog prices for these providers are only a fallback for responses without a cost.

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

```bash
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

You can write the file by hand, or let [`spectra:pricing-scrape`](#scraping-prices) fill it from the providers' pricing pages. The format is [the one above](#the-pricing-file), with these rules:

- A model in your file replaces the **whole** bundled model, so list every row it needs: other tiers, [long-context prices](#long-context-prices) and [dated prices](#dated-prices). `spectra:pricing-scrape` copies those rows from the bundled model for you.
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

<a name="scraping-prices"></a>
## Scraping Prices with Firecrawl {#scraping-prices}

`spectra:pricing-scrape` reads the providers' pricing pages with [Firecrawl](https://firecrawl.dev) and saves the prices that differ from the bundled catalog, and the models it doesn't have, to [your pricing file](#your-own-prices). Use it to pick up a price change or a new model before the next Spectra release.

Add your Firecrawl API key to your `.env` file:

```bash
FIRECRAWL_API_KEY=fc-...
SPECTRA_PRICING_PATH=resources/spectra/pricing.json
```

Then run the command, review the changes it prints, and commit the file:

```shell
php artisan spectra:pricing-scrape            # the providers your app uses
php artisan spectra:pricing-scrape openai anthropic
php artisan spectra:pricing-scrape --dry-run  # show the changes, write nothing
```

Only the command calls Firecrawl. Tracking reads the saved file and never makes a network call.

<a name="scraped-providers"></a>
### Which Providers Are Scraped {#scraped-providers}

Each page costs Firecrawl credits, so the command only scrapes the providers you use. Without provider names, it picks, in this order:

1. The providers you name: `php artisan spectra:pricing-scrape openai`.
2. The providers in `SPECTRA_SCRAPE_PROVIDERS`, for example `SPECTRA_SCRAPE_PROVIDERS=openai,anthropic`.
3. The providers Spectra has tracked requests for. An app that only calls OpenAI only scrapes OpenAI.

If none of these gives a provider with pricing pages (a new app that hasn't tracked anything yet), the command stops without calling Firecrawl. Pass `--all` to scrape every provider that has pages.

<a name="firecrawl-how-it-works"></a>
### How It Works {#firecrawl-how-it-works}

1. For each provider, Firecrawl renders the pages in the provider's `sources`. Its [JSON extraction](https://docs.firecrawl.dev/features/llm-extract) fills a schema with one row per model, tier and long-context threshold, and the same call returns the page text. The schema only offers the tiers and price fields the provider's catalog uses, and the prompt lists the catalog's model ids, so the page's names map to API ids.
2. The extraction copies prices as printed, with the quantity they are for (per 1M tokens, per 1K characters, per image). Spectra converts them to cents. The LLM never does the arithmetic.
3. Spectra compares the rows with the bundled catalog. A model matches by id, alias, dated snapshot, or display name, whatever the word order (`Claude Haiku 3.5` matches `claude-3-5-haiku`). Only the prices in the row that is in effect today change. The other rows (other tiers, long-context and dated prices) are kept. A page label for a tier the catalog names differently counts as the catalog's tier: OpenAI's "Fast" is `priority`.
4. Only **changed** and **new** models are saved to your file. A model whose prices match the bundled catalog stays out of it, so it keeps following the bundled catalog when you upgrade Spectra.
5. Each run replaces the sections of the providers it scraped. A provider whose prices all match is removed from your file. A provider Firecrawl couldn't read keeps its current entries. A provider only your file has is rewritten whole, with the changes applied.

LLM extraction makes mistakes: on real pricing pages it copies prices from the next row, reads an Audio row as the text prices, multiplies prices by 1000, and invents prices that aren't there. Spectra checks every row and prints why it skipped one:

- **Every price must be on the page, next to its model**: a price that isn't in the page text on the model's row (or within a few lines of its name) is ignored.
- **Our price is kept while the page still shows it next to the model**: a different number on the same row is more likely another column or tier.
- **No column mix-ups**: a scraped price that equals another price of the same catalog row (the audio input price read as the input price) skips the row.
- **No impossible prices**: cached input above input, output below input, or long-context prices below the regular ones. No row in the bundled catalog does this.
- **No outliers**: a price 10 times higher or lower than the catalog's.
- **Only what billing can use**: a unit price for a token model (or the reverse), a price in a different unit than the catalog's, a token price the page prints per minute, second, image or page, a per-character price for a single character, a new tier or model missing a price billing needs, or a new model without a `standard` price.
- **New models must be real and billable**: a new model's id must be printed on the page as a name of its own (not only inside a link), so ids the extraction makes up from display names are skipped. A new model must be a kind of model (text, image, audio, video) one of the provider's handlers bills: fal.ai's speech and 3D models are skipped, because Spectra's fal.ai handlers only track images and video. A new per-unit model whose price is 10 times higher or lower than every catalog price for the same unit is skipped (an OCR price "per 1,000 pages" read as per page).

The checks favour skipping a real change over saving a wrong one, so a run can miss some changes. Run it again later, or add them to the file by hand. Review the printed changes, and the file diff, before you deploy it. Each change prints the page line it was confirmed on, so you can check that the price sits in the right column and tier without opening the page; the checks above can't tell a batch price from a standard one printed on the same row. New models get a type and capabilities guessed from their pricing unit.

<a name="firecrawl-options"></a>
### Options {#firecrawl-options}

| Option | Description |
| --- | --- |
| `provider*` | The providers to scrape. Default: see [Which Providers Are Scraped](#scraped-providers). |
| `--all` | Scrape every provider that has pricing pages in `sources`. |
| `--url=*` | Scrape these pages instead of the provider's own. Needs exactly one provider. |
| `--path=` | Write this file instead of `spectra.costs.pricing_path` (default: `resources/spectra/pricing.json`). Pointed at the package's `resources/pricing/catalog.json`, it [updates the bundled catalog](#keeping-prices-current) itself. |
| `--dry-run` | Print the changes without writing the file. |

<a name="pricing-pages"></a>
### Pricing Pages

Each provider's section lists its pages in `sources`; they are the pages in the [table below](#keeping-prices-current). OpenRouter has none, because it reports each request's cost itself. To scrape other pages, pass them with `--url`, or give a provider of your own `sources` in your file:

```json
"my-provider": {
    "sources": ["https://my-provider.example/pricing"],
    "models": { }
}
```

| Config key | Env | Default | Description |
| --- | --- | --- | --- |
| `costs.firecrawl.api_key` | `FIRECRAWL_API_KEY` | none | Your Firecrawl API key |
| `costs.firecrawl.url` | `FIRECRAWL_API_URL` | `https://api.firecrawl.dev/v2` | The API base URL, for a self-hosted Firecrawl |
| `costs.firecrawl.timeout` | `FIRECRAWL_TIMEOUT` | `180` | Seconds to wait for each page (Firecrawl allows up to 300) |
| `costs.firecrawl.providers` | `SPECTRA_SCRAPE_PROVIDERS` | none | Comma-separated providers to scrape when you don't name any, e.g. `openai,anthropic` |

<a name="caching"></a>
## Caching

The pricing files are read and indexed once per process. With the cache on (the default), the index is kept in your cache store instead, so a PHP-FPM request doesn't parse the files again. The cached index is rebuilt when a pricing file changes, when the package is updated, and after `spectra:pricing-scrape` writes a file.

```bash
SPECTRA_PRICING_CACHE=true          # default
SPECTRA_PRICING_CACHE_STORE=redis   # default: your default cache store
SPECTRA_PRICING_CACHE_TTL=86400     # seconds; 0 keeps it until a file changes
```

A cache store that can't be read or written never stops cost tracking: Spectra logs a warning once per process and builds the index from the files. Warnings about entries the files can't load are logged when the index is built, not on every request.

A long-running process loads the index once and keeps it in memory, so queue workers, Horizon and Octane keep using the old prices until they restart. After you change a pricing file, restart them the way you do after a deploy (`php artisan queue:restart`, `php artisan horizon:terminate` or `php artisan octane:reload`).

<a name="keeping-prices-current"></a>
## Keeping Prices Current

The bundled catalog is generated from the providers' pricing pages and reviewed before each release. Provider prices change, and new models ship often, so it needs a refresh from time to time. Between releases, you can pick up new prices yourself with [`spectra:pricing-scrape`](#scraping-prices).

**Prices last reviewed: 2026-11-04.**

Maintainers refresh the catalog in the package repository with:

```bash
composer pricing-scrape
```

It runs `spectra:pricing-scrape --all` against `resources/pricing/catalog.json`, so each provider's section is rewritten whole: every model, with the changes the pages confirm, and its `notes` kept. Review the diff against the provider's pricing page before committing it. The pages are:

| Provider | Source |
| --- | --- |
| OpenAI | https://developers.openai.com/api/docs/pricing |
| Anthropic | https://platform.claude.com/docs/en/about-claude/pricing |
| Google (Gemini) | https://ai.google.dev/gemini-api/docs/pricing |
| xAI | https://docs.x.ai/developers/pricing |
| Mistral | https://docs.mistral.ai/inference/pricing |
| Cohere | https://cohere.com/pricing |
| Groq | https://console.groq.com/docs/models |
| OpenRouter | https://openrouter.ai/api/v1/models and, for image models, `https://openrouter.ai/api/v1/images/models/{author}/{slug}/endpoints` (not scraped: edited by hand) |
| Replicate | https://replicate.com/pricing |
| fal.ai | https://fal.ai/pricing |
| Scaleway | https://www.scaleway.com/en/pricing/model-as-a-service/ |
| ElevenLabs | https://elevenlabs.io/pricing/api |

<a name="drift-check-against-models-dev"></a>
### Drift Check Against models.dev

To spot prices that need a review, the repository has a maintainer command (not shipped with the package) that compares the catalog with the community-maintained [models.dev](https://models.dev) catalog:

```bash
vendor/bin/testbench spectra:pricing-drift
```

It lists token prices that differ (including long-context prices, and fallbacks such as a missing cached-input price billing at the input price) and models that models.dev prices but the catalog doesn't have. Per-unit models (images, audio, video), providers in another currency (Scaleway), and providers models.dev doesn't cover (Replicate, fal.ai, ElevenLabs) are skipped. Models missing from the OpenRouter catalog aren't listed, because OpenRouter reports each request's cost itself.

models.dev is not a price source: check every finding against the provider's pricing page. When a difference is intentional (models.dev is wrong, or it counts something differently), record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. A recorded difference is reported again if models.dev changes that price. The `pricing-drift` workflow runs the check every Monday and keeps one open issue with the unreviewed findings.

When a provider renames a model, keep the old id in `aliases`. When a provider shuts a model down, keep the entry and set `retired_on`, so older requests keep their prices. The `PricingCatalogInvariantsTest` checks the shape of the catalog.

::: info Providers without token pricing
**Ollama** runs models locally, so its requests are deliberately tracked at **$0**. The catalog has no Ollama section, so its models are never reported as unpriced.

**Google Imagen** is shut down: Google removed the Imagen models from the Gemini API and from its pricing page. Spectra still records a `:generateImages` call, with its images, but has no price for it, so it is reported as [unpriced](/costs#zero-cost-requests). Use the Gemini image models (`gemini-2.5-flash-image`, `gemini-3.1-flash-image`, `gemini-3-pro-image`), which are priced.

**ElevenLabs** bills subscription plans in credits, not per request. The bundled prices are the published pay-as-you-go per-character API rates, as an approximation. If your plan's effective rate differs, set the prices in [your own file](#your-own-prices).
:::
