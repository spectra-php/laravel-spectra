<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;

/**
 * Fluent builder for one model in a provider pricing catalog.
 *
 * Token prices are US cents (or the catalog's currency) per 1M tokens; unit
 * prices are cents per unit. A tier may be registered more than once to
 * describe prices that depend on the prompt size (`aboveInputTokens`) or that
 * change on a known date (`since`); PricingLookup picks the matching entry.
 *
 * @phpstan-type TierEntry array{
 *     tier: string,
 *     input_price: float,
 *     output_price: float,
 *     cached_input_price: float|null,
 *     cache_write_5m_price: float|null,
 *     cache_write_1h_price: float|null,
 *     price_per_unit: float|null,
 *     audio_input_price: float|null,
 *     audio_output_price: float|null,
 *     image_input_price: float|null,
 *     image_output_price: float|null,
 *     above_input_tokens: int|null,
 *     since: string|null,
 * }
 */
class ModelDefinition
{
    protected ?string $displayName = null;

    protected string $type = 'text';

    protected string $pricingUnit = 'tokens';

    protected bool $canGenerateText = false;

    protected bool $canGenerateImages = false;

    protected bool $canGenerateVideo = false;

    protected bool $canGenerateAudio = false;

    /** @var list<string> */
    protected array $aliases = [];

    protected ?string $retiredOn = null;

    /** @var list<TierEntry> */
    protected array $tiers = [];

    /**
     * Create a new model definition for the given model id.
     */
    public function __construct(
        protected string $internalName,
    ) {}

    /**
     * Set the name the dashboard shows for the model.
     */
    public function displayName(string $name): static
    {
        $this->displayName = $name;

        return $this;
    }

    /**
     * Set the kind of model (text, image, video, audio, embedding…).
     */
    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Set the unit the model is billed in (tokens, characters, images, seconds…).
     */
    public function pricingUnit(PricingUnit|string $unit): static
    {
        if (is_string($unit)) {
            $unit = PricingUnit::from($unit);
        }

        $this->pricingUnit = $unit->value;

        return $this;
    }

    /**
     * Other model ids that bill exactly like this one (`-latest` aliases,
     * dated snapshots, provider-prefixed ids, retired ids that now redirect).
     */
    public function aliases(string ...$aliases): static
    {
        $this->aliases = array_values(collect([...$this->aliases, ...$aliases])->uniqueStrict()->all());

        return $this;
    }

    /**
     * Mark the model as shut down by the provider. It stays in the catalog so
     * historical requests keep their prices; the dashboard can flag it.
     */
    public function retiredOn(string $date): static
    {
        $this->retiredOn = $this->normalizeDate($date);

        return $this;
    }

    /**
     * Mark the model as able to generate text.
     */
    public function canGenerateText(): static
    {
        $this->canGenerateText = true;

        return $this;
    }

    /**
     * Mark the model as able to generate images.
     */
    public function canGenerateImages(): static
    {
        $this->canGenerateImages = true;

        return $this;
    }

    /**
     * Mark the model as able to generate video.
     */
    public function canGenerateVideo(): static
    {
        $this->canGenerateVideo = true;

        return $this;
    }

    /**
     * Mark the model as able to generate audio.
     */
    public function canGenerateAudio(): static
    {
        $this->canGenerateAudio = true;

        return $this;
    }

    /**
     * Set the cost for the standard pricing tier.
     */
    public function cost(
        float $inputPrice = 0,
        float $outputPrice = 0,
        ?float $cachedInputPrice = null,
        ?float $cacheWrite5mPrice = null,
        ?float $cacheWrite1hPrice = null,
        ?float $pricePerUnit = null,
        ?float $audioInputPrice = null,
        ?float $audioOutputPrice = null,
        ?float $imageInputPrice = null,
        ?float $imageOutputPrice = null,
        ?int $aboveInputTokens = null,
        ?string $since = null,
    ): static {
        return $this->tier(
            PricingTier::Standard,
            $inputPrice,
            $outputPrice,
            $cachedInputPrice,
            $cacheWrite5mPrice,
            $cacheWrite1hPrice,
            $pricePerUnit,
            $audioInputPrice,
            $audioOutputPrice,
            $imageInputPrice,
            $imageOutputPrice,
            $aboveInputTokens,
            $since,
        );
    }

    /**
     * Register prices for a tier.
     *
     * @param  float|null  $cacheWrite5mPrice  Price of cache writes (Anthropic 5-minute TTL; OpenAI's only write rate).
     * @param  float|null  $audioInputPrice  Price of audio input tokens; falls back to inputPrice.
     * @param  float|null  $imageInputPrice  Price of image input tokens; falls back to inputPrice.
     * @param  int|null  $aboveInputTokens  Apply these prices only when the prompt is larger than this many tokens.
     * @param  string|null  $since  Apply these prices only to requests made on or after this date (Y-m-d).
     */
    public function tier(
        PricingTier|string $tier,
        float $inputPrice = 0,
        float $outputPrice = 0,
        ?float $cachedInputPrice = null,
        ?float $cacheWrite5mPrice = null,
        ?float $cacheWrite1hPrice = null,
        ?float $pricePerUnit = null,
        ?float $audioInputPrice = null,
        ?float $audioOutputPrice = null,
        ?float $imageInputPrice = null,
        ?float $imageOutputPrice = null,
        ?int $aboveInputTokens = null,
        ?string $since = null,
    ): static {
        $this->tiers[] = [
            'tier' => $tier instanceof PricingTier ? $tier->value : $tier,
            'input_price' => $inputPrice,
            'output_price' => $outputPrice,
            'cached_input_price' => $cachedInputPrice,
            'cache_write_5m_price' => $cacheWrite5mPrice,
            'cache_write_1h_price' => $cacheWrite1hPrice,
            'price_per_unit' => $pricePerUnit,
            'audio_input_price' => $audioInputPrice,
            'audio_output_price' => $audioOutputPrice,
            'image_input_price' => $imageInputPrice,
            'image_output_price' => $imageOutputPrice,
            'above_input_tokens' => $aboveInputTokens,
            'since' => $since !== null ? $this->normalizeDate($since) : null,
        ];

        return $this;
    }

    /**
     * Get the model id the catalog lists the model under.
     */
    public function getInternalName(): string
    {
        return $this->internalName;
    }

    /**
     * Get the other model ids that bill like this one.
     *
     * @return list<string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Get the model's name, type, unit, capabilities, aliases and retirement date as an array.
     *
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
            'aliases' => $this->aliases,
            'retired_on' => $this->retiredOn,
        ];
    }

    /**
     * Get every registered tier price row, in registration order.
     *
     * @return list<TierEntry>
     */
    public function toTiersArray(): array
    {
        return $this->tiers;
    }

    /**
     * Validate a Y-m-d pricing date and return it normalized.
     */
    protected function normalizeDate(string $date): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d')
                ?? throw new InvalidArgumentException;
        } catch (\Throwable) {
            throw new InvalidArgumentException("Pricing dates must use the Y-m-d format, got '{$date}' for {$this->internalName}.");
        }
    }
}
