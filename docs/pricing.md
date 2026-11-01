# Pricing

Spectra includes a built-in pricing catalog defined through PHP classes. Each supported provider has a pricing class that registers its models, pricing tiers, and capabilities. Pricing lookups are resolved in memory at runtime with zero database queries.

<a name="how-it-works"></a>
## How It Works

Pricing is defined by classes that extend `ProviderPricing`. Each class registers models using a fluent `ModelDefinition` builder inside a `define()` method. Spectra ships with pricing classes for all built-in providers — these are mapped in your `config/spectra.php` under `costs.pricing`:

```php
'costs' => [
    'pricing' => [
        'openai' => \Spectra\Pricing\OpenAIPricing::class,
        'anthropic' => \Spectra\Pricing\AnthropicPricing::class,
        'google' => \Spectra\Pricing\GooglePricing::class,
        // ...
    ],
],
```

On first access, `PricingLookup` resolves all pricing classes and builds an in-memory index. No database tables or migrations are involved.

All prices are in **cents** of the catalog's currency (US cents unless the class says otherwise, see [Currency](#currency)). Token prices are cents per 1M tokens. Unit prices are cents per unit.

<a name="model-definition-api"></a>
## Model Definition API

The callback passed to `$this->model()` receives a `ModelDefinition` instance with the following methods:

### Model Properties

| Method | Description |
| --- | --- |
| `displayName(string)` | Human-readable name shown in the dashboard |
| `type(string)` | Model type: `text`, `embedding`, `image`, `audio`, `video` (default: `text`) |
| `pricingUnit(PricingUnit\|string)` | What the price is charged against (default: `tokens`). See [Pricing Units](#pricing-units). |
| `aliases(string ...)` | Other model ids that bill exactly like this model: `-latest` aliases, dated snapshots, provider-prefixed ids, or old ids that now redirect |
| `retiredOn(string)` | The date (`Y-m-d`) the provider shut the model down. The model stays in the catalog, so older requests keep their prices. |

### Capability Flags

| Method | Description |
| --- | --- |
| `canGenerateText()` | Model can generate text output |
| `canGenerateImages()` | Model can generate images |
| `canGenerateVideo()` | Model can generate video |
| `canGenerateAudio()` | Model can generate audio |

<a name="pricing-units"></a>
### Pricing Units

| Unit | `pricePerUnit` is charged per |
| --- | --- |
| `tokens` | Not used. Token models use `inputPrice`, `outputPrice` and the other token prices below. |
| `minute` | Minute of audio |
| `second` | Second of audio or video |
| `characters` | 1M characters of input text |
| `image` | Generated image |
| `megapixel` | Megapixel of generated output |
| `video` | Generated video |
| `search` | Search unit (for example, a rerank search) |
| `page` | Document page (for example, OCR) |
| `request` | Successful request (a flat price per call) |

### Tier Pricing

The `tier()` method defines the prices for one pricing tier. Call it more than once for more tiers:

```php
$model->tier(string|PricingTier $tier, ...pricing parameters)
```

Tier names: `standard`, `batch`, `flex`, `priority`, `fast`, `ultrafast`, and `hd`. A request for a tier the model does not list is billed at the model's `standard` price.

**Token prices** (cents per 1M tokens):

| Parameter | Description |
| --- | --- |
| `inputPrice` | Uncached input tokens |
| `outputPrice` | Output tokens, including reasoning tokens |
| `cachedInputPrice` | Input tokens read from the prompt cache. Falls back to `inputPrice`. |
| `cacheWrite5mPrice` | Input tokens written to the prompt cache (Anthropic 5-minute writes, and OpenAI's only write rate). Falls back to `inputPrice`. |
| `cacheWrite1hPrice` | Input tokens written to the prompt cache with a 1-hour lifetime (Anthropic). Falls back to `cacheWrite5mPrice`, then `inputPrice`. |
| `audioInputPrice` | Audio input tokens. Falls back to `inputPrice`. |
| `audioOutputPrice` | Audio output tokens. Falls back to `outputPrice`. |
| `imageInputPrice` | Image input tokens. Falls back to `inputPrice`. |
| `imageOutputPrice` | Image output tokens. Falls back to `outputPrice`. |

**Unit price** (cents):

| Parameter | Description |
| --- | --- |
| `pricePerUnit` | Cost per unit of the model's pricing unit |

**Conditions** (optional):

| Parameter | Description |
| --- | --- |
| `aboveInputTokens` | Use these prices only when the prompt is larger than this many tokens. See [Long-Context Prices](#long-context-prices). |
| `since` | Use these prices only for requests made on or after this date (`Y-m-d`). See [Prices That Change on a Date](#dated-prices). |

The `cost()` method is a shorthand for `tier('standard', ...)` and takes the same parameters:

```php
$model->cost(inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125);
// Equivalent to:
$model->tier('standard', inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125);
```

<a name="examples"></a>
## Examples

**Token-based LLM with multiple tiers:**

```php
$this->model('gpt-4o', fn ($m) => $m
    ->displayName('GPT-4o')
    ->canGenerateText()
    ->tier('standard', inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125)
    ->tier('batch', inputPrice: 125, outputPrice: 500)
    ->tier('priority', inputPrice: 425, outputPrice: 1700, cachedInputPrice: 212.5));
```

**Token-based image model with image token prices:**

```php
$this->model('gpt-image-1.5', fn ($m) => $m
    ->displayName('GPT Image 1.5')
    ->type('image')
    ->canGenerateImages()
    ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125, imageInputPrice: 800, imageOutputPrice: 3200));
```

**Per-image model:**

```php
$this->model('dall-e-3', fn ($m) => $m
    ->displayName('DALL-E 3')
    ->type('image')
    ->pricingUnit('image')
    ->canGenerateImages()
    ->tier('standard', pricePerUnit: 4)
    ->tier('hd', pricePerUnit: 8));
```

**Per-megapixel image model:**

```php
$this->model('fal-ai/flux/dev', fn ($m) => $m
    ->displayName('FLUX.1 Dev')
    ->type('image')
    ->pricingUnit('megapixel')
    ->canGenerateImages()
    ->tier('standard', pricePerUnit: 2.5));
```

**Audio transcription (per minute):**

```php
$this->model('whisper-1', fn ($m) => $m
    ->displayName('Whisper')
    ->type('audio')
    ->pricingUnit('minute')
    ->canGenerateText()
    ->tier('standard', pricePerUnit: 0.6));
```

**Video (per second):**

```php
$this->model('sora-2', fn ($m) => $m
    ->displayName('Sora 2')
    ->type('video')
    ->pricingUnit('second')
    ->canGenerateVideo()
    ->tier('standard', pricePerUnit: 10));
```

**OCR (per page):**

```php
$this->model('mistral-ocr-latest', fn ($m) => $m
    ->displayName('Mistral OCR 4.1')
    ->pricingUnit('page')
    ->tier('standard', pricePerUnit: 0.4)
    ->tier('batch', pricePerUnit: 0.2));
```

**Anthropic model with cache write prices, aliases, and a retirement date:**

```php
$this->model('claude-sonnet-4', fn ($m) => $m
    ->displayName('Claude Sonnet 4')
    ->aliases('claude-sonnet-4-20250514', 'claude-sonnet-4-0')
    ->retiredOn('2026-06-15')
    ->canGenerateText()
    ->tier('standard',
        inputPrice: 300,
        outputPrice: 1500,
        cachedInputPrice: 30,
        cacheWrite5mPrice: 375,
        cacheWrite1hPrice: 600,
    ));
```

<a name="long-context-prices"></a>
## Long-Context Prices

Some models cost more when the prompt is long. Register the same tier twice: once with the normal prices, and once with `aboveInputTokens`:

```php
$this->model('claude-haiku-5-5', fn ($m) => $m
    ->displayName('Claude Haiku 5.5')
    ->canGenerateText()
    ->tier('standard', inputPrice: 10, outputPrice: 50, cachedInputPrice: 1)
    ->tier('standard', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5, aboveInputTokens: 100_000));
```

The prompt size is the full prompt: uncached tokens, cache reads, and cache writes. When the prompt is larger than `aboveInputTokens`, the whole request (input and output) uses that row. When several rows match, the one with the highest threshold wins.

<a name="dated-prices"></a>
## Prices That Change on a Date

When a provider announces a new price for a future date, add a second row with `since`:

```php
$this->model('my-model', fn ($m) => $m
    ->displayName('My Model')
    ->canGenerateText()
    ->cost(inputPrice: 100, outputPrice: 400)
    ->cost(inputPrice: 80, outputPrice: 320, since: '2026-12-01'));
```

Spectra uses the date the request was made. Requests before 2026-12-01 use the first row, and requests on or after that date use the second row. When rows have both `since` and `aboveInputTokens`, Spectra first picks the newest `since` date that has started, then the highest prompt-size threshold the prompt is above.

Dates must use the `Y-m-d` format. Any other format throws an `InvalidArgumentException`.

<a name="model-lookup"></a>
## How Spectra Finds a Model

Spectra looks up the `provider` and model id in this order:

1. The exact model id.
2. A model that lists the id in `aliases()`.
3. The id without a trailing snapshot date. For example, `claude-opus-4-8-20260528` matches `claude-opus-4-8`, and `gpt-4o-2024-08-06` matches `gpt-4o`.
4. The id without an OpenRouter routing suffix (`:nitro`, `:floor`, `:online`, `:exacto`, `:thinking`, `:extended`, `:beta`). For example, `anthropic/claude-sonnet-4.5:nitro` matches `anthropic/claude-sonnet-4.5`.

The shorter ids in steps 3 and 4 are also checked against aliases. The `:free` suffix is never removed, so a free model never picks up the price of the paid model.

A catalog entry always wins over an alias with the same id. Because the exact id is checked first, a dated snapshot with its own entry (for example, `gpt-4o-2024-05-13`) keeps its own price.

Define models under their **undated** id, and the dated snapshots resolve on their own. Names that differ in any other way do not match, and the request is billed **0**.

<a name="snapshot-first"></a>
### The Served Model Is Priced First

The model in the response can differ from the model you asked for. This happens with aliases, routers, and retired models that redirect to a newer model. Spectra prices the model the provider actually served (stored in the `snapshot` column) when the catalog knows it. If the catalog does not know it, Spectra prices the model you asked for.

<a name="reported-costs"></a>
## Reported Costs

Some providers send the real cost of a request in the response:

- **OpenRouter**: `usage.cost`
- **xAI**: `cost_in_usd_ticks`

When a response has this cost, Spectra stores it as is, instead of the catalog price. It is converted from US dollars to your [reporting currency](#currency). The catalog prices for these providers are only a fallback for responses without a cost.

<a name="currency"></a>
## Currency

Each pricing class says which currency its prices use. The default is `USD`:

```php
public function currency(): string
{
    return 'EUR';
}
```

Spectra stores costs in cents of the currency set in `spectra.costs.currency` (default `USD`). When a catalog uses another currency, Spectra converts its prices with the rates in `spectra.costs.exchange_rates`. The built-in Scaleway catalog is in EUR.

A rate says how many units of your reporting currency one unit of the other currency is worth:

```php
// config/spectra.php — report in USD
'costs' => [
    'currency' => 'USD',
    'exchange_rates' => [
        'EUR' => 1.17,
    ],
],
```

If you report in EUR, add a rate for USD instead (`'USD' => 0.86`). It is used for every USD catalog and for costs that providers report in US dollars.

When a rate is missing, Spectra counts the prices 1:1 and logs a warning once per process. Spectra does not fetch exchange rates. Update them from time to time.

<a name="customizing-pricing"></a>
## Customizing Pricing

To add custom models or override built-in pricing, extend the provider's pricing class and override the `populate()` method:

```php
<?php

namespace App\Pricing;

use Spectra\Pricing\OpenAIPricing;

class MyOpenAIPricing extends OpenAIPricing
{
    protected function populate(): void
    {
        $this->model('ft:gpt-4o:my-org:custom-suffix', fn ($m) => $m
            ->displayName('My Fine-tune')
            ->canGenerateText()
            ->cost(inputPrice: 300, outputPrice: 1200));
    }
}
```

Then update your config to use your class instead:

```php
'costs' => [
    'pricing' => [
        'openai' => \App\Pricing\MyOpenAIPricing::class,
        // ...
    ],
],
```

Built-in models from `define()` are always included automatically. The `populate()` method adds your custom models on top.

<a name="tool-call-pricing"></a>
## Tool Call Pricing

Some providers charge separately for built-in tool calls (web search, file search, code interpreter). These fees are defined by overriding the `toolCallPricing()` method in a pricing class. The values are cents per call:

```php
public function toolCallPricing(): array
{
    return [
        'web_search_call' => 1.0,          // 1 cent per call
        'file_search_call' => 0.25,        // 0.25 cents per call
        'code_interpreter_call' => 3.0,    // 3 cents per call
    ];
}
```

The keys match the tool call types Spectra counts in the response (for example, OpenAI's `web_search_call` output items) or the keys a handler reports in `Metrics::$toolCalls` (for example, Anthropic's `web_search_requests`). Tool call fees are added to the total cost of the request. They are converted with the catalog's currency, like the other prices.

<a name="adding-a-new-provider"></a>
## Adding a New Provider

To add pricing for a provider not included in Spectra, create a class that extends `ProviderPricing`:

```php
<?php

namespace App\Pricing;

use Spectra\Pricing\ProviderPricing;

class MyProviderPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'my-provider';
    }

    protected function define(): void
    {
        $this->model('my-model', fn ($m) => $m
            ->displayName('My Model')
            ->canGenerateText()
            ->cost(inputPrice: 100, outputPrice: 400));
    }
}
```

Register it in your config:

```php
'costs' => [
    'pricing' => [
        'my-provider' => \App\Pricing\MyProviderPricing::class,
        // ...existing providers...
    ],
],
```

<a name="scraped-prices"></a>
## Scraped Prices

Spectra can bill with prices from a file next to the built-in catalog: the prices [`spectra:pricing-scrape`](#scraping-prices) reads from the providers' pricing pages, or a few prices you write yourself without a PHP class.

Scraped prices are off by default. Turn them on in your `.env` file:

```bash
SPECTRA_USE_SCRAPED_PRICES=true
# Optional, the default is resources/spectra/scraped-prices.json
SPECTRA_SCRAPED_PRICES_PATH=/path/to/scraped-prices.json
```

| `use_scraped_prices` | Prices come from |
| --- | --- |
| `false` (default) | The pricing classes in `costs.pricing`. The file is ignored. |
| `true` | The file first. Each model in the file replaces the built-in model with the same id. Every model the file doesn't list keeps its built-in price. |

Because the built-in catalog fills the gaps, the file only needs the models you want to change. Commit the file, so every environment bills the same prices.

::: info Nothing changes until you opt in
With `use_scraped_prices` off, Spectra prices requests exactly as before: the file is never read, and tracking never calls Firecrawl. You don't need to publish the config again either. If your `config/spectra.php` was published before these options existed, Spectra adds `use_scraped_prices`, `scraped_prices_path` and `firecrawl` with their defaults, so `SPECTRA_USE_SCRAPED_PRICES` and `FIRECRAWL_API_KEY` work right away. Every class in `costs.pricing` is still used, including two classes for the same provider.
:::

### File Format

The file is JSON. Models are keyed by id and use the same fields as the [Model Definition API](#model-definition-api), in `snake_case`. Prices are cents, like the PHP catalog: cents per 1M tokens, or cents per unit.

```json
{
    "providers": {
        "openai": {
            "currency": "USD",
            "models": {
                "gpt-4o": {
                    "display_name": "GPT-4o",
                    "capabilities": ["text"],
                    "aliases": ["gpt-4o-2024-08-06"],
                    "tiers": [
                        { "tier": "standard", "input_price": 250, "output_price": 1000, "cached_input_price": 125 },
                        { "tier": "batch", "input_price": 125, "output_price": 500 }
                    ]
                },
                "dall-e-3": {
                    "type": "image",
                    "pricing_unit": "image",
                    "capabilities": ["images"],
                    "tiers": [{ "tier": "standard", "price_per_unit": 4 }]
                }
            }
        }
    }
}
```

| Model field | Default | Description |
| --- | --- | --- |
| `display_name` | none | Name shown in the dashboard |
| `type` | `text` | `text`, `embedding`, `image`, `audio` or `video` |
| `pricing_unit` | `tokens` | See [Pricing Units](#pricing-units) |
| `capabilities` | `[]` | Any of `text`, `images`, `video`, `audio` |
| `aliases` | `[]` | Other ids that bill like this model |
| `retired_on` | none | `Y-m-d` |
| `tiers` | required | One object per row. Fields: `tier`, `input_price`, `output_price`, `cached_input_price`, `cache_write_5m_price`, `cache_write_1h_price`, `price_per_unit`, `audio_input_price`, `audio_output_price`, `image_input_price`, `image_output_price`, `above_input_tokens`, `since`. A missing price works like a missing parameter in `tier()`. |

A model in the file replaces the **whole** built-in model, so list every row it needs: other tiers, [long-context prices](#long-context-prices) and [dated prices](#dated-prices). `spectra:pricing-scrape` copies those rows from the built-in model for you.

The provider's `currency` defaults to its built-in catalog's currency. A provider whose currency differs from its built-in catalog is skipped, because its prices would be converted with the wrong rate. You can also add providers that have no built-in catalog.

::: info Problems never stop cost tracking
If the file is missing or isn't valid JSON, Spectra logs a warning and uses the built-in prices. A model that can't be read (an unknown tier, a price that isn't a number, a bad date) is skipped with a warning, and the rest of the file still loads.
:::

<a name="scraping-prices"></a>
## Scraping Prices with Firecrawl

`spectra:pricing-scrape` reads the providers' pricing pages with [Firecrawl](https://firecrawl.dev) and saves the prices that differ from the built-in catalog to the [scraped prices file](#scraped-prices). Use it to pick up a price change or a new model before the next Spectra release.

Add your Firecrawl API key to your `.env` file:

```bash
FIRECRAWL_API_KEY=fc-...
```

Then run the command, review the changes it prints, and turn scraped prices on:

```shell
php artisan spectra:pricing-scrape            # the providers your app uses
php artisan spectra:pricing-scrape openai anthropic
php artisan spectra:pricing-scrape --dry-run  # show the changes, write nothing
```

```bash
SPECTRA_USE_SCRAPED_PRICES=true
```

Only the command calls Firecrawl. Tracking reads the saved file and never makes a network call.

<a name="scraped-providers"></a>
### Which Providers Are Scraped

Each page costs Firecrawl credits, so the command only scrapes the providers you use. Without provider names, it picks, in this order:

1. The providers you name: `php artisan spectra:pricing-scrape openai`.
2. The providers in `SPECTRA_SCRAPE_PROVIDERS`, for example `SPECTRA_SCRAPE_PROVIDERS=openai,anthropic`.
3. The providers Spectra has tracked requests for. An app that only calls OpenAI only scrapes OpenAI.

If none of these gives a provider with pricing pages (a new app that hasn't tracked anything yet), the command stops without calling Firecrawl. Pass `--all` to scrape every provider.

### How It Works

1. For each provider, Firecrawl renders the pages from the pricing class's `sources()` method. Its [JSON extraction](https://docs.firecrawl.dev/features/llm-extract) fills a schema with one row per model, tier and long-context threshold, and the same call returns the page text. The schema only offers the tiers and price fields the provider's catalog uses, and the prompt lists the catalog's model ids, so the page's names map to API ids.
2. The extraction copies prices as printed, with the quantity they are for (per 1M tokens, per 1K characters, per image). Spectra converts them to cents. The LLM never does the arithmetic.
3. Spectra compares the rows with the built-in catalog. A model matches by id, alias, dated snapshot, or display name, whatever the word order (`Claude Haiku 3.5` matches `claude-3-5-haiku`). Only the prices in the row that is in effect today change. The other rows (other tiers, long-context and dated prices) are kept. A page label for a tier the catalog names differently counts as the catalog's tier: OpenAI's "Fast" is `priority`.
4. Only **changed** and **new** models are saved. A model whose prices match the catalog stays out of the file, so it keeps following the built-in catalog when you upgrade Spectra.
5. Each run replaces the sections of the providers it scraped. A provider whose prices all match is removed from the file. A provider Firecrawl couldn't read keeps its current entries.

LLM extraction makes mistakes: on real pricing pages it copies prices from the next row, reads an Audio row as the text prices, multiplies prices by 1000, and invents prices that aren't there. Spectra checks every row and prints why it skipped one:

- **The page must show the price next to the model**: on the table row that names the model (or its "Text" / "Audio" continuation rows), or below a heading that names it. Numbers inside model names and long prose lines don't count.
- **The quantity must match the page**: a price said to be per 1K must be on a line that says per 1K, and the reverse.
- **Our price is kept while the page still shows it next to the model**: a different number on the same row is more likely another column or tier.
- **No column mix-ups**: a scraped price that equals another price of the same catalog row (the audio input price read as the input price) skips the row.
- **No impossible prices**: cached input above input, output below input, or long-context prices below the regular ones. No row in the built-in catalog does this.
- **No outliers**: a price 10 times higher or lower than the catalog's.
- **Only what billing can use**: a unit price for a token model (or the reverse), a price in a different unit than the catalog's, a per-character price for a single character, a new tier or model missing a price billing needs, or a new model without a `standard` price.

The checks favour skipping a real change over saving a wrong one, so a run can miss some changes. Run it again later, or add them to the file by hand. Review the printed changes, and the file diff, before you deploy it. New models get a type and capabilities guessed from their pricing unit.

### Options

| Option | Description |
| --- | --- |
| `provider*` | The providers to scrape. Default: see [Which Providers Are Scraped](#scraped-providers). |
| `--all` | Scrape every provider whose pricing class returns pages from `sources()`. |
| `--url=*` | Scrape these pages instead of the provider's own. Needs exactly one provider. |
| `--path=` | Read and write this file instead of `spectra.costs.scraped_prices_path`. |
| `--dry-run` | Print the changes without writing the file. |

### Pricing Pages

Each built-in pricing class lists its pages in `sources()`. They are the pages in the [table below](#keeping-prices-current). OpenRouter has none, because it reports each request's cost itself. To scrape other pages, or a provider of your own, override `sources()`:

```php
class MyProviderPricing extends ProviderPricing
{
    public function sources(): array
    {
        return ['https://my-provider.example/pricing'];
    }
}
```

| Config key | Env | Default | Description |
| --- | --- | --- | --- |
| `costs.firecrawl.api_key` | `FIRECRAWL_API_KEY` | none | Your Firecrawl API key |
| `costs.firecrawl.url` | `FIRECRAWL_API_URL` | `https://api.firecrawl.dev/v2` | The API base URL, for a self-hosted Firecrawl |
| `costs.firecrawl.timeout` | `FIRECRAWL_TIMEOUT` | `180` | Seconds to wait for each page (Firecrawl allows up to 300) |
| `costs.firecrawl.providers` | `SPECTRA_SCRAPE_PROVIDERS` | none | Comma-separated providers to scrape when you don't name any, e.g. `openai,anthropic` |

<a name="keeping-prices-current"></a>
## Keeping Prices Current

The built-in catalog is **hand-maintained**. Spectra does not fetch prices from provider APIs. Provider prices change, and new models ship often, so the catalog needs a review from time to time. Between releases, you can pick up new prices yourself with [`spectra:pricing-scrape`](#scraping-prices).

**Prices last reviewed: 2026-10-29.**

When reviewing, check each provider's official pricing page and update the matching `ProviderPricing` class under `src/Pricing/`:

| Provider | Source |
| --- | --- |
| OpenAI | https://developers.openai.com/api/docs/pricing |
| Anthropic | https://platform.claude.com/docs/en/about-claude/pricing |
| Google (Gemini) | https://ai.google.dev/gemini-api/docs/pricing |
| xAI | https://docs.x.ai/developers/pricing |
| Mistral | https://docs.mistral.ai/inference/pricing |
| Cohere | https://cohere.com/pricing |
| Groq | https://console.groq.com/docs/models |
| OpenRouter | https://openrouter.ai/models |
| Replicate | https://replicate.com/pricing |
| fal.ai | https://fal.ai/pricing |
| Scaleway | https://www.scaleway.com/en/pricing/model-as-a-service/ |
| ElevenLabs | https://elevenlabs.io/pricing/api |

### Drift Check Against models.dev

To spot prices that need a review, the repository has a maintainer command (not shipped with the package) that compares the catalog with the community-maintained [models.dev](https://models.dev) catalog:

```bash
vendor/bin/testbench spectra:pricing-drift
```

It lists token prices that differ (including long-context prices, and fallbacks such as a missing cached-input price billing at the input price) and models that models.dev prices but the catalog doesn't have. Per-unit models (images, audio, video), catalogs in another currency (Scaleway), and providers models.dev doesn't cover (Replicate, fal.ai, ElevenLabs) are skipped. Models missing from the OpenRouter catalog aren't listed, because OpenRouter reports each request's cost itself.

models.dev is not a price source: check every finding against the provider's pricing page. When a difference is intentional (models.dev is wrong, or it counts something differently), record it with `--update-baseline` and commit `workbench/pricing-drift-baseline.json`. A recorded difference is reported again if models.dev changes that price. The `pricing-drift` workflow runs the check every Monday and keeps one open issue with the unreviewed findings.

When a provider renames a model, keep the old id in `aliases()`. When a provider shuts a model down, keep the entry and add `retiredOn()`, so older requests keep their prices. The `PricingCatalogInvariantsTest` checks the shape of the catalog.

::: info Providers without token pricing
**Ollama** runs models locally, so its requests are deliberately tracked at **$0** — there is no `OllamaPricing` class.

**ElevenLabs** bills subscription plans in credits rather than per request. The bundled `ElevenLabsPricing` uses the published pay-as-you-go per-character API rates as an approximation; if your plan's effective rate differs, extend the class and override the prices.
:::
