# Costs

Spectra automatically calculates the cost of every tracked AI request. Costs are computed using the [pricing catalog](/pricing) and the metrics extracted from each response — tokens, image count, audio duration, or character count depending on the model type.

All costs are stored in **cents** with decimal precision (for example, `0.004200` cents), providing sub-cent accuracy for high-volume operations.

<a name="how-cost-calculation-works"></a>
## How Cost Calculation Works

When a tracked request completes, Spectra's cost calculation pipeline runs through the following steps:

1. **Model identification** — The model name is extracted from the API response (not the request), ensuring accuracy even when the provider resolves an alias to a specific snapshot.
2. **Pricing lookup** — The in-memory pricing catalog is queried for a matching provider, model, and tier combination.
3. **Pricing unit resolution** — The `pricing_unit` on the model definition determines which cost formula to apply: `tokens`, `image`, `video`, `minute`, `second`, or `characters`.
4. **Cost calculation** — The appropriate formula is applied using the extracted metrics and the resolved prices.
5. **Persistence** — The calculated cost is stored in `prompt_cost`, `completion_cost`, and `total_cost_in_cents` on the request record.

<a name="pricing-units"></a>
## Pricing Units

Each model in the pricing catalog has a `pricing_unit` that determines how its cost is calculated:

| Unit | Used By | Calculation Basis |
| --- | --- | --- |
| `tokens` | LLMs, embeddings, GPT Image models | Per 1M tokens (separate input and output prices) |
| `image` | DALL-E, Stable Diffusion | Per image generated |
| `video` | Sora | Per video generated |
| `minute` | Whisper STT | Audio duration in minutes |
| `second` | Replicate video/audio | Duration in seconds |
| `characters` | OpenAI TTS | Per 1M characters of input text |

<a name="token-based-cost-formula"></a>
## Token-Based Cost Formula

For models priced per token — the most common case for language models, embeddings, and newer image models — Spectra applies a formula that accounts for both regular and cached prompt tokens:

```
regular_prompt_tokens = max(0, prompt_tokens - cached_tokens)
cached_price = cached_input_price ?? input_price

prompt_cost = (regular_prompt_tokens × input_price + cached_tokens × cached_price) / 1,000,000
completion_cost = (completion_tokens × output_price) / 1,000,000
total_cost = prompt_cost + completion_cost
```

Prices in the pricing catalog are stored in **cents per million tokens**. The division by 1,000,000 converts from per-million pricing to the actual cost for the tokens consumed.

<a name="unit-based-cost-formulas"></a>
## Unit-Based Cost Formulas

For non-token models, cost is calculated based on the model's pricing unit and the relevant metric extracted from the response:

| Unit | Formula |
| --- | --- |
| Per image | `image_count × price_per_unit` |
| Per video | `video_count × price_per_unit` |
| Per minute | `(duration_seconds / 60) × price_per_unit` |
| Per second | `duration_seconds × price_per_unit` |
| Per character | `(input_characters × price_per_unit) / 1,000,000` |

<a name="pricing-tiers"></a>
## Pricing Tiers

Some providers offer multiple pricing tiers with different price points for the same model. Spectra supports per-tier pricing, allowing accurate cost tracking regardless of how you access the API.

### OpenAI Tiers

| Tier | Description |
| --- | --- |
| `standard` | Regular pricing (default) |
| `batch` | Approximately 50% discount for asynchronous processing with up to 24-hour turnaround |
| `flex` | Approximately 30% discount with higher latency tolerance |
| `priority` | Premium pricing with faster processing guarantees |

### Anthropic Tiers

| Tier | Description |
| --- | --- |
| `standard` | Regular pricing (default) |
| `batch` | Discounted rate for asynchronous batch processing |

<a name="configuring-tiers"></a>
### Configuring the Default Tier

Configure the default tier per provider in `config/spectra.php`. This tier is used when no explicit tier is specified on the request:

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

<a name="per-request-tier"></a>
### Per-Request Tier Override

Override the tier on a per-request basis using `withAITracking`:

```php
Http::withAITracking('openai', 'gpt-4o', ['pricing_tier' => 'batch'])
    ->post('https://api.openai.com/v1/chat/completions', [...]);
```

Or set the tier globally for all requests in the current process:

```php
Spectra::withPricingTier('batch');

// All subsequent requests use batch pricing
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
| `total_cost_in_cents` | Total cost of the request (cents) |

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

<a name="zero-cost-requests"></a>
## Zero-Cost Requests

A request will have zero cost when:

- The model is not in the pricing catalog (a warning is logged)
- The pricing tier doesn't exist and the `standard` tier fallback also has no entry
- The extracted metrics are zero (e.g. zero tokens, zero images)

To add pricing for a missing model, create a [custom pricing class](/pricing#customizing-pricing).
