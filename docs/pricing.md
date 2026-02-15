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

On first access, `PricingLookup` resolves all pricing classes and builds a flat indexed array for O(1) lookups. No database tables or migrations are involved.

<a name="model-definition-api"></a>
## Model Definition API

The callback passed to `$this->model()` receives a `ModelDefinition` instance with the following methods:

### Model Properties

| Method | Description |
| --- | --- |
| `displayName(string)` | Human-readable name shown in the dashboard |
| `type(string)` | Model type: `text`, `embedding`, `image`, `audio`, `video` (default: `text`) |
| `pricingUnit(string)` | How cost is calculated: `tokens`, `image`, `video`, `minute`, `second`, `characters` (default: `tokens`) |

### Capability Flags

| Method | Description |
| --- | --- |
| `canGenerateText()` | Model can generate text output |
| `canGenerateImages()` | Model can generate images |
| `canGenerateVideo()` | Model can generate video |
| `canGenerateAudio()` | Model can generate audio |

### Tier Pricing

The `tier()` method defines a pricing tier. Call it multiple times for multiple tiers:

```php
$model->tier(string $tier, ...pricing parameters)
```

**Token-based parameters** (per 1M tokens, in cents):

| Parameter | Description |
| --- | --- |
| `inputPrice` | Cost per 1M input tokens |
| `outputPrice` | Cost per 1M output tokens |
| `cachedInputPrice` | Cost per 1M cached input tokens (falls back to `inputPrice` if null) |
| `cacheWrite5mPrice` | Cost per 1M tokens for 5-minute cache writes (Anthropic) |
| `cacheWrite1hPrice` | Cost per 1M tokens for 1-hour cache writes (Anthropic) |

**Unit-based parameter** (in cents):

| Parameter | Description |
| --- | --- |
| `pricePerUnit` | Cost per unit (image, video, minute, second, or per 1M characters) |

The `cost()` method is a shorthand for `tier('standard', ...)`:

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

**Token-based image model:**

```php
$this->model('gpt-image-1.5', fn ($m) => $m
    ->displayName('GPT Image 1.5')
    ->type('image')
    ->canGenerateImages()
    ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125));
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

**Anthropic model with cache write pricing:**

```php
$this->model('claude-sonnet-4-20250514', fn ($m) => $m
    ->displayName('Claude Sonnet 4')
    ->canGenerateText()
    ->tier('standard',
        inputPrice: 300,
        outputPrice: 1500,
        cachedInputPrice: 30,
        cacheWrite5mPrice: 375,
    ));
```

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

Some providers charge separately for built-in tool calls (web search, file search, code interpreter). These surcharges are defined by overriding the `toolCallPricing()` method in a pricing class:

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

Tool call costs are added on top of the token-based cost for the request.

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
