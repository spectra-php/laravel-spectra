<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Spectra\Enums\PricingTier;
use Spectra\Pricing\ProviderPricing;

/**
 * In-memory index over every configured pricing catalog.
 *
 * Resolves a provider/model pair (including aliases, dated snapshots and
 * routing suffixes) to its catalog entry, and picks the price row for a tier,
 * prompt size and request date.
 *
 * @phpstan-type Price array{
 *     input: int|float,
 *     output: int|float,
 *     cached_input: int|float|null,
 *     cache_write_5m: int|float|null,
 *     cache_write_1h: int|float|null,
 *     price_per_unit: int|float|null,
 *     audio_input: int|float|null,
 *     audio_output: int|float|null,
 *     image_input: int|float|null,
 *     image_output: int|float|null,
 *     above_input_tokens: int|null,
 *     since: string|null,
 * }
 * @phpstan-type ModelData array{
 *     display_name: ?string,
 *     type: string,
 *     pricing_unit: string,
 *     can_generate_text: bool,
 *     can_generate_images: bool,
 *     can_generate_video: bool,
 *     can_generate_audio: bool,
 *     retired_on: ?string,
 *     tiers: array<string, list<Price>>,
 * }
 */
class PricingLookup
{
    /**
     * OpenRouter-style routing suffixes that do not change the token price.
     * `:free` is deliberately absent: free variants must not pick up paid prices.
     */
    protected const ROUTING_SUFFIXES = [':nitro', ':floor', ':online', ':exacto', ':thinking', ':extended', ':beta'];

    /** @var array<string, ModelData> */
    protected array $models = [];

    /** @var array<string, string> "provider|alias" => "provider|model" */
    protected array $aliases = [];

    /** @var array<string, string> Provider slug => currency of its last catalog */
    protected array $currencies = [];

    /**
     * Two catalogs may share a slug (a USD catalog of extra Scaleway models
     * next to the built-in EUR one), so each model keeps its own currency.
     *
     * @var array<string, string> "provider|model" => currency of the catalog that defined it
     */
    protected array $modelCurrencies = [];

    /** @var array<string, string> Provider slug => currency of the catalog that set its tool call prices */
    protected array $toolCallCurrencies = [];

    /** @var array<string, true> "provider|tier" values already warned about (once per process). */
    protected array $warnedTiers = [];

    /** @var array<string, array<string, float>> Provider slug => tool call type => cost in cents */
    protected array $toolCallPricing = [];

    protected bool $resolved = false;

    /**
     * @param  ProviderPricing[]  $pricingClasses
     */
    public function __construct(
        protected array $pricingClasses = []
    ) {}

    /**
     * Get the price row for a model.
     *
     * @param  int|null  $promptTokens  Total prompt size, used to pick long-context prices.
     * @param  CarbonInterface|null  $at  When the request was made, used to pick dated prices (default: now).
     * @return Price|null
     */
    public function get(
        string $provider,
        string $model,
        string|PricingTier|null $tier = null,
        ?int $promptTokens = null,
        ?CarbonInterface $at = null,
    ): ?array {
        $key = $this->resolveKey($provider, $model);

        if ($key === null) {
            return null;
        }

        $tierValue = $tier instanceof PricingTier ? $tier->value : ($tier ?? PricingTier::Standard->value);
        $tiers = $this->models[$key]['tiers'];
        $date = ($at ?? now())->format('Y-m-d');

        $entry = $this->selectEntry($tiers[$tierValue] ?? [], $promptTokens ?? 0, $date);

        // Tiers a model doesn't offer bill at its standard price.
        if ($entry === null && $tierValue !== PricingTier::Standard->value) {
            $entry = $this->selectEntry($tiers[PricingTier::Standard->value] ?? [], $promptTokens ?? 0, $date);
        }

        return $entry;
    }

    /**
     * Check if pricing exists for a specific model.
     */
    public function has(string $provider, string $model, string|PricingTier|null $tier = null): bool
    {
        return $this->get($provider, $model, $tier) !== null;
    }

    /**
     * Whether the model is in the catalog at all (by id, alias or snapshot).
     */
    public function knows(string $provider, string $model): bool
    {
        return $this->resolveKey($provider, $model) !== null;
    }

    /**
     * The catalog id a model resolves to (e.g. an alias resolves to its model).
     */
    public function canonicalModel(string $provider, string $model): ?string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? explode('|', $key, 2)[1] : null;
    }

    /**
     * Get the pricing unit for a model.
     */
    public function getUnit(string $provider, string $model): ?string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->models[$key]['pricing_unit'] : null;
    }

    /**
     * Get the display name for a model.
     */
    public function getDisplayName(string $provider, string $model): ?string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->models[$key]['display_name'] : null;
    }

    /**
     * Get the model type (text, image, audio, video, embedding).
     */
    public function getModelType(string $provider, string $model): ?string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->models[$key]['type'] : null;
    }

    /**
     * The date the provider shut the model down, if it has.
     */
    public function getRetiredOn(string $provider, string $model): ?string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->models[$key]['retired_on'] : null;
    }

    /**
     * Get the capabilities for a model.
     *
     * @return array{text: bool, images: bool, video: bool, audio: bool}
     */
    public function getCapabilities(string $provider, string $model): array
    {
        $key = $this->resolveKey($provider, $model);
        $modelData = $key !== null ? $this->models[$key] : null;

        if ($modelData === null) {
            return ['text' => false, 'images' => false, 'video' => false, 'audio' => false];
        }

        return [
            'text' => $modelData['can_generate_text'],
            'images' => $modelData['can_generate_images'],
            'video' => $modelData['can_generate_video'],
            'audio' => $modelData['can_generate_audio'],
        ];
    }

    /**
     * Get all model data for a specific provider + model pair.
     *
     * @return ModelData|null
     */
    public function getModelData(string $provider, string $model): ?array
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->models[$key] : null;
    }

    /**
     * The currency a provider's catalog is written in (ISO 4217). When two
     * catalogs share the slug, this is the later one's; price a model with
     * modelCurrency() instead.
     */
    public function currency(string $provider): string
    {
        $this->resolve();

        return $this->currencies[$provider] ?? 'USD';
    }

    /**
     * The currency of the catalog that defines a model (ISO 4217), falling
     * back to the provider's currency for a model no catalog knows.
     */
    public function modelCurrency(string $provider, string $model): string
    {
        $key = $this->resolveKey($provider, $model);

        return $key !== null ? $this->modelCurrencies[$key] : $this->currency($provider);
    }

    /**
     * The currency of the provider's tool call prices (ISO 4217).
     */
    public function toolCallCurrency(string $provider): string
    {
        $this->resolve();

        return $this->toolCallCurrencies[$provider] ?? $this->currency($provider);
    }

    /**
     * The tier a provider's requests bill at when neither the request nor the
     * response names one (`spectra.costs.provider_settings.{provider}.default_tier`).
     *
     * A value that isn't a PricingTier (a typo in SPECTRA_OPENAI_PRICING_TIER)
     * bills at standard, so cost and the stored tier agree; it is logged once.
     */
    public function defaultTier(string $provider): PricingTier
    {
        $configured = config("spectra.costs.provider_settings.{$provider}.default_tier");

        if ($configured instanceof PricingTier) {
            return $configured;
        }

        if (blank($configured)) {
            return PricingTier::default();
        }

        $tier = is_string($configured) ? PricingTier::tryFrom($configured) : null;

        if ($tier !== null) {
            return $tier;
        }

        $value = is_scalar($configured) ? (string) $configured : get_debug_type($configured);
        $warningKey = "{$provider}|{$value}";

        if (! isset($this->warnedTiers[$warningKey])) {
            $this->warnedTiers[$warningKey] = true;

            Log::warning("Spectra: unknown pricing tier '{$value}' in spectra.costs.provider_settings.{$provider}.default_tier; billing {$provider} at the standard tier.");
        }

        return PricingTier::default();
    }

    /**
     * Get tool call pricing for a provider.
     *
     * @return array<string, float> Tool call type => cost in cents per call
     */
    public function getToolCallPricing(string $provider): array
    {
        $this->resolve();

        return $this->toolCallPricing[$provider] ?? [];
    }

    /**
     * Get all internal model names that can generate text.
     *
     * @return string[]
     */
    public function canGenerateText(): array
    {
        return $this->modelsWithCapability('can_generate_text');
    }

    /**
     * Get all internal model names that can generate images.
     *
     * @return string[]
     */
    public function canGenerateImages(): array
    {
        return $this->modelsWithCapability('can_generate_images');
    }

    /**
     * Get all internal model names that can generate video.
     *
     * @return string[]
     */
    public function canGenerateVideo(): array
    {
        return $this->modelsWithCapability('can_generate_video');
    }

    /**
     * Get all internal model names that can generate audio.
     *
     * @return string[]
     */
    public function canGenerateAudio(): array
    {
        return $this->modelsWithCapability('can_generate_audio');
    }

    /**
     * @return string[]
     */
    protected function modelsWithCapability(string $field): array
    {
        $this->resolve();

        $result = [];
        foreach ($this->models as $key => $data) {
            if ($data[$field]) {
                // Key is "provider|model", we want just the model part
                $result[] = explode('|', $key, 2)[1];
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Pick the most specific price row: the newest `since` date that has
     * started, then the highest prompt-size threshold the prompt exceeds.
     *
     * @param  list<Price>  $entries
     * @return Price|null
     */
    protected function selectEntry(array $entries, int $promptTokens, string $date): ?array
    {
        $candidates = array_filter($entries, fn (array $entry) => ($entry['since'] === null || $entry['since'] <= $date)
            && ($entry['above_input_tokens'] === null || $promptTokens > $entry['above_input_tokens']));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => [$b['since'] ?? '', $b['above_input_tokens'] ?? -1]
            <=> [$a['since'] ?? '', $a['above_input_tokens'] ?? -1]);

        return $candidates[0];
    }

    /**
     * Build all model definitions from pricing classes. Called once on first access.
     */
    protected function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        foreach ($this->pricingClasses as $pricing) {
            $provider = $pricing->provider();
            $currency = strtoupper($pricing->currency());
            $this->currencies[$provider] = $currency;

            $toolPricing = $pricing->toolCallPricing();
            if (filled($toolPricing)) {
                $this->toolCallPricing[$provider] = $toolPricing;
                $this->toolCallCurrencies[$provider] = $currency;
            }

            foreach ($pricing->models() as $definition) {
                $modelArray = $definition->toModelArray();
                $key = $this->buildKey($provider, $definition->getInternalName());

                $tiers = [];
                foreach ($definition->toTiersArray() as $tierData) {
                    $tiers[$tierData['tier']][] = [
                        'input' => $this->normalizePrice($tierData['input_price']),
                        'output' => $this->normalizePrice($tierData['output_price']),
                        'cached_input' => $this->normalizeNullablePrice($tierData['cached_input_price']),
                        'cache_write_5m' => $this->normalizeNullablePrice($tierData['cache_write_5m_price']),
                        'cache_write_1h' => $this->normalizeNullablePrice($tierData['cache_write_1h_price']),
                        'price_per_unit' => $this->normalizeNullablePrice($tierData['price_per_unit']),
                        'audio_input' => $this->normalizeNullablePrice($tierData['audio_input_price']),
                        'audio_output' => $this->normalizeNullablePrice($tierData['audio_output_price']),
                        'image_input' => $this->normalizeNullablePrice($tierData['image_input_price']),
                        'image_output' => $this->normalizeNullablePrice($tierData['image_output_price']),
                        'above_input_tokens' => $tierData['above_input_tokens'],
                        'since' => $tierData['since'],
                    ];
                }

                $this->modelCurrencies[$key] = $currency;
                $this->models[$key] = [
                    'display_name' => $modelArray['display_name'],
                    'type' => $modelArray['type'],
                    'pricing_unit' => $modelArray['pricing_unit'],
                    'can_generate_text' => $modelArray['can_generate_text'],
                    'can_generate_images' => $modelArray['can_generate_images'],
                    'can_generate_video' => $modelArray['can_generate_video'],
                    'can_generate_audio' => $modelArray['can_generate_audio'],
                    'retired_on' => $modelArray['retired_on'],
                    'tiers' => $tiers,
                ];

                foreach ($definition->getAliases() as $alias) {
                    $this->aliases[$this->buildKey($provider, $alias)] = $key;
                }
            }
        }

        // A real catalog entry always wins over an alias with the same id.
        $this->aliases = array_diff_key($this->aliases, $this->models);

        $this->resolved = true;
    }

    protected function normalizePrice(mixed $price): int|float
    {
        $floatValue = (float) $price;

        if (floor($floatValue) === $floatValue) {
            return (int) $floatValue;
        }

        return $floatValue;
    }

    protected function normalizeNullablePrice(mixed $price): int|float|null
    {
        return $price !== null ? $this->normalizePrice($price) : null;
    }

    protected function buildKey(string $provider, string $model): string
    {
        return "{$provider}|{$model}";
    }

    /**
     * Resolve the catalog key for a provider/model pair.
     *
     * Tries, in order: the exact id, the id without a routing suffix
     * (`anthropic/claude-sonnet-4.5:nitro`), then each of those without a
     * trailing snapshot date (`claude-opus-4-8-20260528` → `claude-opus-4-8`,
     * `gpt-4o-2024-08-06` → `gpt-4o`) — each checked as a catalog id and as an
     * alias. An explicit catalog entry for a dated snapshot always wins,
     * because the exact id is matched first.
     */
    protected function resolveKey(string $provider, string $model): ?string
    {
        $this->resolve();

        foreach ($this->candidateIds($model) as $candidate) {
            $key = $this->buildKey($provider, $candidate);

            if (isset($this->models[$key])) {
                return $key;
            }

            if (isset($this->aliases[$key])) {
                return $this->aliases[$key];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function candidateIds(string $model): array
    {
        $candidates = [$model];

        foreach (self::ROUTING_SUFFIXES as $suffix) {
            if (str_ends_with($model, $suffix)) {
                $candidates[] = substr($model, 0, -strlen($suffix));
            }
        }

        foreach ($candidates as $candidate) {
            $base = preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $candidate);

            if (is_string($base) && $base !== $candidate) {
                $candidates[] = $base;
            }
        }

        return array_values(array_unique($candidates));
    }
}
