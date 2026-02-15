<?php

namespace Spectra\Support\Pricing;

class ModelDefinition
{
    protected ?string $displayName = null;

    protected string $type = 'text';

    protected string $pricingUnit = 'tokens';

    protected bool $canGenerateText = false;

    protected bool $canGenerateImages = false;

    protected bool $canGenerateVideo = false;

    protected bool $canGenerateAudio = false;

    /** @var array<int, array{tier: string, input_price: float, output_price: float, cached_input_price: float|null, cache_write_5m_price: float|null, cache_write_1h_price: float|null, price_per_unit: float|null}> */
    protected array $tiers = [];

    public function __construct(
        protected string $internalName,
    ) {}

    public function displayName(string $name): static
    {
        $this->displayName = $name;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function pricingUnit(string $unit): static
    {
        $this->pricingUnit = $unit;

        return $this;
    }

    public function canGenerateText(): static
    {
        $this->canGenerateText = true;

        return $this;
    }

    public function canGenerateImages(): static
    {
        $this->canGenerateImages = true;

        return $this;
    }

    public function canGenerateVideo(): static
    {
        $this->canGenerateVideo = true;

        return $this;
    }

    public function canGenerateAudio(): static
    {
        $this->canGenerateAudio = true;

        return $this;
    }

    /**
     * Set the cost for the standard pricing tier.
     *
     * A convenience method that creates a 'standard' tier entry.
     * Use this when the model has a single pricing tier.
     */
    public function cost(
        float $inputPrice = 0,
        float $outputPrice = 0,
        ?float $cachedInputPrice = null,
        ?float $cacheWrite5mPrice = null,
        ?float $cacheWrite1hPrice = null,
        ?float $pricePerUnit = null,
    ): static {
        return $this->tier('standard', $inputPrice, $outputPrice, $cachedInputPrice, $cacheWrite5mPrice, $cacheWrite1hPrice, $pricePerUnit);
    }

    public function tier(
        string $tier,
        float $inputPrice = 0,
        float $outputPrice = 0,
        ?float $cachedInputPrice = null,
        ?float $cacheWrite5mPrice = null,
        ?float $cacheWrite1hPrice = null,
        ?float $pricePerUnit = null,
    ): static {
        $this->tiers[] = [
            'tier' => $tier,
            'input_price' => $inputPrice,
            'output_price' => $outputPrice,
            'cached_input_price' => $cachedInputPrice,
            'cache_write_5m_price' => $cacheWrite5mPrice,
            'cache_write_1h_price' => $cacheWrite1hPrice,
            'price_per_unit' => $pricePerUnit,
        ];

        return $this;
    }

    public function getInternalName(): string
    {
        return $this->internalName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelArray(): array
    {
        return [
            'internal_name' => $this->internalName,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'pricing_unit' => $this->pricingUnit,
            'can_generate_text' => $this->canGenerateText,
            'can_generate_images' => $this->canGenerateImages,
            'can_generate_video' => $this->canGenerateVideo,
            'can_generate_audio' => $this->canGenerateAudio,
        ];
    }

    /**
     * @return array<int, array{tier: string, input_price: float, output_price: float, cached_input_price: float|null, cache_write_5m_price: float|null, cache_write_1h_price: float|null, price_per_unit: float|null}>
     */
    public function toTiersArray(): array
    {
        return $this->tiers;
    }
}
