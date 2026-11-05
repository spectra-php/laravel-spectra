<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Illuminate\Support\Arr;
use Spectra\Enums\PricingTier;
use Spectra\Pricing\ProviderPricing;
use Spectra\Pricing\ScrapedPricing;

/**
 * The tiers and price fields a provider's catalog uses.
 *
 * Scraping is limited to them: a price the catalog never reads (OpenAI's
 * 12-hour cache write) would only make a model look changed. Pages and
 * catalogs name the same tier differently (OpenAI's "Fast" is the catalog's
 * `priority`), so a tier the catalog lacks falls back to its synonym.
 */
final class CatalogShape
{
    /** Extraction schema field => tier field. */
    public const FIELDS = [
        'input' => 'input_price',
        'output' => 'output_price',
        'cached_input' => 'cached_input_price',
        'cache_write_5m' => 'cache_write_5m_price',
        'cache_write_1h' => 'cache_write_1h_price',
        'audio_input' => 'audio_input_price',
        'audio_output' => 'audio_output_price',
        'image_input' => 'image_input_price',
        'image_output' => 'image_output_price',
        'price_per_unit' => 'price_per_unit',
    ];

    /** Tiers that are the same thing under another name, depending on the provider. */
    protected const TIER_SYNONYMS = [
        'fast' => 'priority',
        'priority' => 'fast',
    ];

    /**
     * Create a new catalog shape from the tiers and price fields a catalog uses.
     *
     * @param  array<int, string>  $tiers
     * @param  list<string>  $fields  Tier fields (input_price, ...).
     */
    public function __construct(
        public readonly array $tiers,
        public readonly array $fields,
    ) {}

    /**
     * Create the shape of the tiers and price fields a catalog uses.
     *
     * A catalog without models (a provider of your own) allows everything.
     */
    public static function of(ProviderPricing $pricing): self
    {
        $tiers = [];
        $fields = ['input_price' => true, 'output_price' => true];

        foreach ($pricing->models() as $definition) {
            foreach ($definition->toTiersArray() as $row) {
                $tiers[$row['tier']] = true;

                foreach (ScrapedPricing::PRICE_FIELDS as $field) {
                    if ($row[$field] !== null) {
                        $fields[$field] = true;
                    }
                }
            }
        }

        if ($tiers === []) {
            return new self(Arr::pluck(PricingTier::cases(), 'value'), array_values(self::FIELDS));
        }

        return new self(
            array_values(collect(PricingTier::cases())->pluck('value')->filter(fn (string $tier) => isset($tiers[$tier]))->all()),
            array_values(Arr::where(self::FIELDS, fn (string $field) => isset($fields[$field]))),
        );
    }

    /**
     * Get the catalog tier a page's tier label stands for, or null when the
     * catalog has neither the tier nor a synonym of it.
     */
    public function resolveTier(string $tier): ?string
    {
        if (in_array($tier, $this->tiers, true)) {
            return $tier;
        }

        $synonym = self::TIER_SYNONYMS[$tier] ?? null;

        return $synonym !== null && in_array($synonym, $this->tiers, true) ? $synonym : null;
    }

    /**
     * Get the tier labels the extraction may use: the catalog's tiers and their synonyms.
     *
     * @return list<string>
     */
    public function tierLabels(): array
    {
        return array_values(collect(PricingTier::cases())
            ->pluck('value')
            ->filter(fn (string $tier) => $this->resolveTier($tier) !== null)
            ->all());
    }

    /**
     * Determine if the catalog uses this tier price field, so a scraped value for it counts.
     */
    public function allowsField(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
