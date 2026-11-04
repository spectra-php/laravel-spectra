<?php

declare(strict_types=1);

namespace Spectra\Pricing;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Support\Pricing\ModelDefinition;
use Throwable;

/**
 * A provider catalog read from the scraped prices file.
 *
 * Models use the same fields as ModelDefinition, in snake_case, keyed by
 * model id. A model that fails to parse, or has no `standard` tier to fall
 * back to, is skipped with a warning (the built-in entry keeps pricing it),
 * so one bad entry never takes cost tracking down.
 */
class ScrapedPricing extends ProviderPricing
{
    /** Price fields of a tier row, in ModelDefinition::tier() order. */
    public const PRICE_FIELDS = [
        'input_price',
        'output_price',
        'cached_input_price',
        'cache_write_5m_price',
        'cache_write_1h_price',
        'price_per_unit',
        'audio_input_price',
        'audio_output_price',
        'image_input_price',
        'image_output_price',
    ];

    protected const CAPABILITIES = [
        'text' => 'canGenerateText',
        'images' => 'canGenerateImages',
        'video' => 'canGenerateVideo',
        'audio' => 'canGenerateAudio',
    ];

    /**
     * Create a new scraped catalog instance for one provider's entries in the file.
     *
     * @param  array<array-key, mixed>  $data  Model id => model array (a numeric id decodes as an int key).
     */
    public function __construct(
        protected string $slug,
        protected array $data,
        protected string $catalogCurrency = 'USD',
    ) {}

    /**
     * Get the provider slug this scraped catalog was saved under.
     */
    public function provider(): string
    {
        return $this->slug;
    }

    /**
     * Get the currency the scraped prices were written in.
     */
    public function currency(): string
    {
        return $this->catalogCurrency;
    }

    /**
     * Register each scraped model, skipping with a warning any entry that fails to parse.
     */
    protected function define(): void
    {
        foreach ($this->data as $id => $model) {
            try {
                if (! is_array($model)) {
                    throw new InvalidArgumentException('the model must be an object');
                }

                $this->definitions[] = static::definitionFromArray((string) $id, $model);
            } catch (Throwable $e) {
                Log::warning("Spectra: skipped {$this->slug} model '{$id}' in the scraped prices file: {$e->getMessage()}");
            }
        }
    }

    /**
     * Build a model definition from its JSON form.
     *
     * @param  array<mixed>  $model
     */
    public static function definitionFromArray(string $id, array $model): ModelDefinition
    {
        $definition = new ModelDefinition($id);

        if (isset($model['display_name'])) {
            $definition->displayName((string) $model['display_name']);
        }

        $definition->type((string) ($model['type'] ?? 'text'));
        $definition->pricingUnit((string) ($model['pricing_unit'] ?? PricingUnit::Tokens->value));

        foreach ((array) ($model['capabilities'] ?? []) as $capability) {
            $method = static::CAPABILITIES[$capability] ?? throw new InvalidArgumentException("unknown capability '{$capability}'");
            $definition->{$method}();
        }

        if (filled($model['aliases'] ?? null)) {
            $definition->aliases(...Arr::map((array) $model['aliases'], fn (mixed $alias) => (string) $alias));
        }

        if (isset($model['retired_on'])) {
            $definition->retiredOn((string) $model['retired_on']);
        }

        $tiers = $model['tiers'] ?? [];

        if (! is_array($tiers) || $tiers === []) {
            throw new InvalidArgumentException('the model has no tiers');
        }

        foreach ($tiers as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('each tier must be an object');
            }

            $tier = PricingTier::tryFrom((string) ($row['tier'] ?? PricingTier::Standard->value))
                ?? throw new InvalidArgumentException("unknown tier '{$row['tier']}'");

            $prices = [];
            foreach (self::PRICE_FIELDS as $field) {
                $value = $row[$field] ?? null;

                if ($value !== null && ! is_numeric($value)) {
                    throw new InvalidArgumentException("{$field} must be a number");
                }

                $prices[] = $value !== null ? (float) $value : null;
            }

            $definition->tier(
                $tier,
                $prices[0] ?? 0,
                $prices[1] ?? 0,
                ...array_slice($prices, 2),
                aboveInputTokens: isset($row['above_input_tokens']) ? (int) $row['above_input_tokens'] : null,
                since: isset($row['since']) ? (string) $row['since'] : null,
            );
        }

        // Prices fall back to the standard tier, so without one the model
        // would replace the built-in entry and bill nothing.
        if (! in_array(PricingTier::Standard->value, Arr::pluck($definition->toTiersArray(), 'tier'), true)) {
            throw new InvalidArgumentException('the model has no standard tier');
        }

        return $definition;
    }

    /**
     * The JSON form of a model definition, without empty fields.
     *
     * @return array<string, mixed>
     */
    public static function definitionToArray(ModelDefinition $definition): array
    {
        $model = $definition->toModelArray();

        return Arr::where([
            'display_name' => $model['display_name'],
            'type' => $model['type'],
            'pricing_unit' => $model['pricing_unit'],
            'capabilities' => collect([
                'text' => $model['can_generate_text'],
                'images' => $model['can_generate_images'],
                'video' => $model['can_generate_video'],
                'audio' => $model['can_generate_audio'],
            ])->filter()->keys()->all(),
            'aliases' => $model['aliases'],
            'retired_on' => $model['retired_on'],
            'tiers' => Arr::map($definition->toTiersArray(), static::tierToArray(...)),
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * Get the JSON form of a tier row, without its null prices.
     *
     * @param  array<string, mixed>  $row  A ModelDefinition tier entry.
     * @return array<string, mixed>
     */
    public static function tierToArray(array $row): array
    {
        return Arr::whereNotNull($row);
    }
}
