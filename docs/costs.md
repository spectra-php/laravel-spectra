# Costs

Spectra automatically calculates the cost of every tracked AI request. Costs are computed using the [pricing catalog](/pricing) and the metrics extracted from each response — tokens, image count, audio duration, page count, or character count depending on the model type.

All costs are stored in **cents** of your reporting currency (`spectra.costs.currency`, default `USD`) with decimal precision (for example, `0.004200` cents). This gives sub-cent accuracy for high-volume operations. Each request also stores its currency in the `currency` column.

<a name="how-cost-calculation-works"></a>
## How Cost Calculation Works

When a tracked request completes, Spectra's cost calculation runs through these steps:

1. **Reported cost** — If the provider sent the real cost in the response (OpenRouter, xAI), Spectra uses it. See [Reported Cost](#reported-cost).
2. **Model identification** — Spectra prices the model the provider actually served (from the response, stored as `snapshot`) when the catalog knows it. Otherwise it prices the model you asked for. See [How Spectra Finds a Model](/pricing#model-lookup).
3. **Pricing lookup** — The in-memory pricing catalog is queried for the provider, model, and [tier](#pricing-tiers). The prompt size picks [long-context prices](/pricing#long-context-prices), and the request date picks [dated prices](/pricing#dated-prices).
4. **Pricing unit resolution** — The `pricing_unit` on the model definition decides which formula to use.
5. **Cost calculation** — The formula is applied to the extracted metrics and the prices, converted to your [reporting currency](#currency-conversion). Then the [cost multiplier](#cost-multiplier) and [tool call fees](#tool-call-fees) are applied.
6. **Persistence** — The cost is stored in `prompt_cost`, `completion_cost`, and `total_cost_in_cents` on the request record.

<a name="pricing-units"></a>
## Pricing Units

Each model in the pricing catalog has a `pricing_unit` that decides how its cost is calculated:

| Unit | Used By | Calculation Basis |
| --- | --- | --- |
| `tokens` | LLMs, embeddings, GPT Image models, token-billed audio models | Per 1M tokens (separate input, cache, audio, image, and output prices) |
| `image` | DALL-E, Stable Diffusion | Per image generated |
| `megapixel` | fal.ai FLUX models | Per megapixel of output |
| `video` | Video models billed per video | Per video generated |
| `minute` | Whisper STT | Audio duration in minutes |
| `second` | Sora, Veo, Replicate video/audio | Duration in seconds |
| `characters` | TTS models billed per character | Per 1M characters of input text |
| `search` | Cohere Rerank | Per search unit |
| `page` | OCR models | Per page |
| `request` | Models with a flat price per call | Per successful request |

<a name="token-model"></a>
## How Tokens Are Counted

Every provider reports usage in its own way. Spectra turns it into one shape for all providers:

- **Prompt tokens** (`prompt_tokens`) are the **full** prompt: uncached tokens + cache reads + cache writes.
- **Completion tokens** (`completion_tokens`) are the **full** output, including reasoning tokens.

The other fields are a breakdown of these two totals, so nothing is counted twice:

| Field | Part of | Stored in |
| --- | --- | --- |
| Cache reads | Prompt tokens | `cached_tokens` |
| Cache writes (5-minute and 1-hour) | Prompt tokens | `cache_write_tokens` |
| Audio input tokens | Prompt tokens | Used for cost only |
| Image input tokens | Prompt tokens | Used for cost only |
| Reasoning tokens | Completion tokens | `reasoning_tokens` |
| Audio output tokens | Completion tokens | Used for cost only |
| Image output tokens | Completion tokens | Used for cost only |

<a name="token-based-cost-formula"></a>
## Token-Based Cost Formula

For models priced per token, Spectra splits the prompt and the output into parts and bills each part at its own price. This is the formula `CostCalculator::forTokens()` uses:

```
# Split the prompt. Each part takes what is left, in this order.
cache_read   = min(cached_tokens,          prompt_tokens)
cache_write  = min(cache_write_tokens,     prompt_tokens - cache_read)
write_1h     = min(cache_write_1h_tokens,  cache_write)
write_5m     = cache_write - write_1h
audio_in     = min(audio_input_tokens,     what is left)
image_in     = min(image_input_tokens,     what is left)
uncached     = what is left

prompt_cost = ( uncached   × input_price
              + cache_read × (cached_input_price   ?? input_price)
              + write_5m   × (cache_write_5m_price ?? input_price)
              + write_1h   × (cache_write_1h_price ?? cache_write_5m_price ?? input_price)
              + audio_in   × (audio_input_price    ?? input_price)
              + image_in   × (image_input_price    ?? input_price)
              ) / 1,000,000

# Split the output.
audio_out = min(audio_output_tokens, completion_tokens)
image_out = min(image_output_tokens, completion_tokens - audio_out)
text_out  = completion_tokens - audio_out - image_out

completion_cost = ( text_out  × output_price
                  + audio_out × (audio_output_price ?? output_price)
                  + image_out × (image_output_price ?? output_price)
                  ) / 1,000,000

total_cost = prompt_cost + completion_cost
```

Reasoning tokens are part of `text_out`, so they are billed at the output price.

Prices in the pricing catalog are stored in **cents per million tokens**. The division by 1,000,000 converts from per-million pricing to the actual cost for the tokens used. A price the catalog leaves empty falls back to the plain input or output price, as shown above.

<a name="unit-based-cost-formulas"></a>
## Unit-Based Cost Formulas

For non-token models, cost is calculated from the model's pricing unit and the matching metric from the response:

| Unit | Formula |
| --- | --- |
| Per image | `image_count × price_per_unit` |
| Per megapixel | `megapixels × price_per_unit` |
| Per video | `video_count × price_per_unit` |
| Per minute | `(duration_seconds / 60) × price_per_unit` |
| Per second | `duration_seconds × price_per_unit` |
| Per character | `(input_characters × price_per_unit) / 1,000,000` |
| Per search | `search_count × price_per_unit` |
| Per page | `page_count × price_per_unit` |
| Per request | `price_per_unit` for a successful request, `0` for a failed one |

Unit-based costs are stored in `total_cost_in_cents`. They have no prompt and completion split.

<a name="tool-call-fees"></a>
## Tool Call Fees

Some providers charge for built-in tools, such as web search or code interpreter. Spectra counts these calls in the response and adds a fee for each one, using the provider's [`toolCallPricing()`](/pricing#tool-call-pricing). Tool call fees are added to `total_cost_in_cents` only, so the total can be larger than `prompt_cost + completion_cost`. The counts are stored in `tool_call_counts`.

<a name="cost-multiplier"></a>
## Cost Multiplier

Some providers charge more for certain requests. For example, Anthropic charges 1.1 times the price for US-only inference, which the response shows in `usage.inference_geo`. Spectra reads this from the response and multiplies the model cost (`prompt_cost`, `completion_cost`, and the total) by it. Tool call fees are not multiplied.

fal.ai video models cost more or less than their catalog price at another resolution or without audio. Spectra reads `resolution` and `generate_audio` from the request and scales the price the same way.

Custom providers can do the same with the [`ExtractsCostMultiplier`](/custom-providers#extracts-cost-multiplier) contract, or with [`ExtractsCostMultiplierForModel`](/custom-providers#extracts-cost-multiplier-for-model) when the multiplier depends on the model.

<a name="reported-cost"></a>
## Reported Cost

When the provider sends the real cost of the request (OpenRouter `usage.cost`, xAI `cost_in_usd_ticks`), Spectra uses it as the total cost, instead of the catalog price, tool fees, and multiplier. The reported cost is in US dollars, so it is converted to your reporting currency. `prompt_cost` and `completion_cost` keep the same share of the total as the catalog estimate gave them.

<a name="currency-conversion"></a>
## Currency Conversion

Spectra stores every cost in your reporting currency (`spectra.costs.currency`). Catalog prices written in another currency are converted with the rates in `spectra.costs.exchange_rates`. The built-in Scaleway catalog is in EUR, so if you report in USD, add a rate for EUR:

```php
'costs' => [
    'currency' => 'USD',
    'exchange_rates' => [
        'EUR' => 1.17, // 1 EUR is worth 1.17 USD
    ],
],
```

If you report in another currency, add a rate for `USD` too. It is used for the USD catalogs (including their tool call fees) and for costs that providers report. A missing rate counts as 1:1, and Spectra logs a warning once per process. See [Currency](/pricing#currency).

<a name="pricing-tiers"></a>
## Pricing Tiers

Some providers offer several pricing tiers with different prices for the same model. Spectra supports per-tier pricing, so costs are right no matter how you call the API.

| Tier | Description | Built-in catalogs |
| --- | --- | --- |
| `standard` | Regular pricing (default) | All |
| `batch` | Lower price for asynchronous processing, with up to 24-hour turnaround | OpenAI, Anthropic, Google, Mistral, Groq, xAI |
| `flex` | Lower price with higher latency | OpenAI, Google |
| `priority` | Higher price for faster processing (OpenAI calls this "Fast") | OpenAI, Google, xAI |
| `fast` | Anthropic fast mode (`speed: fast`) | Anthropic |
| `ultrafast` | OpenAI's fastest tier | OpenAI |
| `hd` | Higher-quality output for media models (for example, HD images or 1080p video) | OpenAI, Google, Replicate, xAI |

A model that does not list the requested tier is billed at its `standard` price.

<a name="tier-order"></a>
### Which Tier Is Used

Spectra picks the tier in this order:

1. A tier you set yourself, with `Spectra::withPricingTier()`, `$ctx->withPricingTier()`, or the `pricing_tier` option.
2. The tier the provider says it served, in the response (for example, OpenAI's `service_tier`).
3. The tier the request asked for (for example, `service_tier: flex`, or Anthropic's `speed: fast`).
4. The provider's `default_tier` from the config.
5. `standard`.

The tier in the response wins over the tier in the request. A `priority` request that the provider served at `standard` is priced at `standard`.

<a name="configuring-tiers"></a>
### Configuring the Default Tier

Configure the default tier per provider in `config/spectra.php`. It is used when you did not set a tier and neither the request nor the response says which tier was used:

```php
'costs' => [
    'provider_settings' => [
        'openai' => [
            'default_tier' => env('SPECTRA_OPENAI_PRICING_TIER', 'standard'),
        ],
        'anthropic' => [
            'default_tier' => env('SPECTRA_ANTHROPIC_PRICING_TIER', 'standard'),
        ],
    ],
],
```

Any provider slug may be listed here.

<a name="per-request-tier"></a>
### Per-Request Tier Override

Override the tier for one request using `withAITracking`:

```php
Http::withAITracking('openai', 'gpt-4o', ['pricing_tier' => 'batch'])
    ->post('https://api.openai.com/v1/chat/completions', [...]);
```

Or set the tier for all requests in the current request or job:

```php
Spectra::withPricingTier('batch');

// All later requests use batch pricing
// ...

Spectra::clearGlobals();
```

<a name="querying-costs"></a>
## Querying Costs

Spectra stores cost data on every `SpectraRequest` record:

| Column | Description |
| --- | --- |
| `prompt_cost` | Cost of input/prompt tokens (cents) |
| `completion_cost` | Cost of output/completion tokens (cents) |
| `total_cost_in_cents` | Total cost of the request, including tool call fees (cents) |
| `currency` | The reporting currency the cost is in (for example, `USD`) |
| `pricing_tier` | The tier the request was priced at |

You can query costs directly through the model:

```php
use Spectra\Models\SpectraRequest;

// Total spend today
$todaysCost = SpectraRequest::whereDate('created_at', today())
    ->sum('total_cost_in_cents');

// Cost by provider
$costByProvider = SpectraRequest::selectRaw('provider, SUM(total_cost_in_cents) as total')
    ->groupBy('provider')
    ->pluck('total', 'provider');

// Most expensive requests
$expensive = SpectraRequest::orderByDesc('total_cost_in_cents')
    ->limit(10)
    ->get();
```

For per-user or per-team cost tracking with enforced limits, see [Budgets](/budgets).

<a name="cost-estimation"></a>
## Cost Estimation

Estimate the cost of a request before making it:

```php
use Spectra\Support\Pricing\CostCalculator;

$calculator = app(CostCalculator::class);

// Estimate cost for a given number of tokens
$estimate = $calculator->estimate(
    provider: 'openai',
    model: 'gpt-4o',
    estimatedPromptTokens: 1000,
    estimatedCompletionTokens: 500,
    pricingTier: 'standard',
);

$estimate['total_cost_in_cents']; // 0.75

// Get the per-token cost for a model
$perToken = $calculator->getCostPerToken('openai', 'gpt-4o');
$perToken['input_per_token'];         // 0.00025
$perToken['output_per_token'];        // 0.001
$perToken['cached_input_per_token'];  // 0.000125
```

To estimate with cache reads, cache writes, or audio tokens, pass a `TokenMetrics` object to `forTokens()`:

```php
use Spectra\Data\TokenMetrics;

$estimate = $calculator->forTokens('anthropic', 'claude-sonnet-4-6', new TokenMetrics(
    promptTokens: 10_000,     // full prompt, including the cache reads and writes below
    completionTokens: 500,
    cachedTokens: 8_000,
    cacheWriteTokens: 1_000,
));
```

<a name="zero-cost-requests"></a>
## Zero-Cost Requests

A request will have zero cost when:

- The model is not in the pricing catalog (not by its id, an alias, or a snapshot date)
- The pricing tier doesn't exist and the `standard` tier fallback also has no entry
- The extracted metrics are zero (for example, zero tokens or zero images)
- The model is priced per request and the request failed
- An OpenAI Chat Completions stream was sent without `stream_options.include_usage` (see [Streaming](/usage#streaming))

To add pricing for a missing model, create a [custom pricing class](/pricing#customizing-pricing).
