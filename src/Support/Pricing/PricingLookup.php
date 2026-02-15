<?php

namespace Spectra\Support\Pricing;

use Spectra\Enums\PricingTier;
use Spectra\Pricing\ProviderPricing;

class PricingLookup
{
    /** @var array<string, array{display_name: ?string, type: string, pricing_unit: string, can_generate_text: bool, can_generate_images: bool, can_generate_video: bool, can_generate_audio: bool, tiers: array<string, array{input: int|float, output: int|float, cached_input: int|float|null, cache_write_5m: int|float|null, cache_write_1h: int|float|null, price_per_unit: int|float|null}>}> */
    protected array $models = [];

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
     * Get pricing for a specific model.
     *
     * @return array{input: int|float, output: int|float, cached_input?: int|float|null, cache_write_5m?: int|float|null, cache_write_1h?: int|float|null, price_per_unit?: int|float|null}|null
     */
    public function get(string $provider, string $model, string|PricingTier|null $tier = null): ?array
    {
        $this->resolve();

        $tierValue = $tier instanceof PricingTier ? $tier->value : ($tier ?? 'standard');
        $key = $this->buildKey($provider, $model);

        $modelData = $this->models[$key] ?? null;

        if ($modelData === null) {
            return null;
        }

        $tierData = $modelData['tiers'][$tierValue] ?? null;

        // Fall back to standard tier
        if ($tierData === null && $tierValue !== 'standard') {
            $tierData = $modelData['tiers']['standard'] ?? null;
        }

        return $tierData;
    }

    /**
     * Check if pricing exists for a specific model.
     */
    public function has(string $provider, string $model, string|PricingTier|null $tier = null): bool
    {
        return $this->get($provider, $model, $tier) !== null;
    }

    /**
     * Get the pricing unit for a model.
     */
    public function getUnit(string $provider, string $model): ?string
    {
        $this->resolve();

        $key = $this->buildKey($provider, $model);

        return isset($this->models[$key]) ? $this->models[$key]['pricing_unit'] : null;
    }

    /**
     * Get the display name for a model.
     */
    public function getDisplayName(string $provider, string $model): ?string
    {
        $this->resolve();

        $key = $this->buildKey($provider, $model);

        return isset($this->models[$key]) ? $this->models[$key]['display_name'] : null;
    }

    /**
     * Get the model type (text, image, audio, video, embedding).
     */
    public function getModelType(string $provider, string $model): ?string
    {
        $this->resolve();

        $key = $this->buildKey($provider, $model);

        return isset($this->models[$key]) ? $this->models[$key]['type'] : null;
    }

    /**
     * Get the capabilities for a model.
     *
     * @return array{text: bool, images: bool, video: bool, audio: bool}
     */
    public function getCapabilities(string $provider, string $model): array
    {
        $this->resolve();

        $key = $this->buildKey($provider, $model);
        $modelData = $this->models[$key] ?? null;

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
     * @return array{display_name: ?string, type: string, pricing_unit: string, can_generate_text: bool, can_generate_images: bool, can_generate_video: bool, can_generate_audio: bool, tiers: array<string, array{input: int|float, output: int|float, cached_input: int|float|null, cache_write_5m: int|float|null, cache_write_1h: int|float|null, price_per_unit: int|float|null}>}|null
     */
    public function getModelData(string $provider, string $model): ?array
    {
        $this->resolve();

        $key = $this->buildKey($provider, $model);

        return $this->models[$key] ?? null;
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

        return array_unique($result);
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

            $toolPricing = $pricing->toolCallPricing();
            if (! empty($toolPricing)) {
                $this->toolCallPricing[$provider] = $toolPricing;
            }

            foreach ($pricing->models() as $definition) {
                $modelArray = $definition->toModelArray();
                $tiersArray = $definition->toTiersArray();

                $key = $this->buildKey($provider, $definition->getInternalName());

                $tiers = [];
                foreach ($tiersArray as $tierData) {
                    $tiers[$tierData['tier']] = [
                        'input' => $this->normalizePrice($tierData['input_price']),
                        'output' => $this->normalizePrice($tierData['output_price']),
                        'cached_input' => $tierData['cached_input_price'] !== null ? $this->normalizePrice($tierData['cached_input_price']) : null,
                        'cache_write_5m' => $tierData['cache_write_5m_price'] !== null ? $this->normalizePrice($tierData['cache_write_5m_price']) : null,
                        'cache_write_1h' => $tierData['cache_write_1h_price'] !== null ? $this->normalizePrice($tierData['cache_write_1h_price']) : null,
                        'price_per_unit' => $tierData['price_per_unit'] !== null ? $this->normalizePrice($tierData['price_per_unit']) : null,
                    ];
                }

                $this->models[$key] = [
                    'display_name' => $modelArray['display_name'],
                    'type' => $modelArray['type'],
                    'pricing_unit' => $modelArray['pricing_unit'],
                    'can_generate_text' => $modelArray['can_generate_text'],
                    'can_generate_images' => $modelArray['can_generate_images'],
                    'can_generate_video' => $modelArray['can_generate_video'],
                    'can_generate_audio' => $modelArray['can_generate_audio'],
                    'tiers' => $tiers,
                ];
            }
        }

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

    protected function buildKey(string $provider, string $model): string
    {
        return "{$provider}|{$model}";
    }
}
