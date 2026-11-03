<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Pricing\ScrapedPricing;
use Spectra\Support\Pricing\ModelDefinition;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Compares scraped price rows with a provider's catalog.
 *
 * Only models whose prices differ, and models the catalog doesn't have, end
 * up in the result. A changed model keeps every catalog row (long-context,
 * dated, other tiers) and only the scraped prices of the row in effect today
 * are replaced, so the JSON entry is a complete replacement for the model.
 *
 * Rows that look like extraction mistakes are skipped and reported: prices
 * the page text doesn't show next to the model, tiers and price fields the
 * catalog doesn't use, ids that aren't API ids, prices off by OUTLIER_RATIO
 * or more, a per-unit price for a token model (or the reverse), and new rows
 * missing a price they need.
 *
 * @phpstan-import-type TierEntry from ModelDefinition
 *
 * @phpstan-type ScrapedRow array{tier: string, above: int|null, prices: array<string, float>, scales: array<string, float>}
 * @phpstan-type ScrapedModel array{display_name: string|null, unit: string|null, names: list<string>, rows: array<string, ScrapedRow>}
 */
class ScrapedPriceMapper
{
    /** A scraped price this many times higher or lower than ours is treated as a mistake. */
    public const OUTLIER_RATIO = 10;

    protected const MODEL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,199}$/';

    /** @var array<string, list<PriceChange>> */
    protected array $changes = [];

    /** @var list<string> */
    protected array $skipped = [];

    protected ?PageText $page = null;

    /**
     * @param  list<array<string, mixed>>  $rows  Rows from FirecrawlPriceScraper.
     * @param  string|null  $today  Y-m-d; picks the dated row in effect (default: today).
     * @param  PageText|null  $page  The pages' text; prices it doesn't confirm are ignored.
     */
    public function map(ProviderPricing $pricing, array $rows, ?string $today = null, ?PageText $page = null): ScrapedPrices
    {
        $today ??= now()->format('Y-m-d');
        $this->page = $page;

        $definitions = [];
        foreach ($pricing->models() as $definition) {
            $definitions[$definition->getInternalName()] = $definition;
        }

        $models = [];
        $newModels = [];
        $matched = 0;

        foreach ($this->group($pricing, $definitions, $rows, $page) as $id => $scraped) {
            if (isset($definitions[$id])) {
                $matched++;
                $entry = $this->applyToKnownModel($id, $definitions[$id], $scraped, $today);
            } else {
                $entry = $this->newModel($id, $scraped);

                if ($entry !== null) {
                    $newModels[] = $id;
                }
            }

            if ($entry !== null) {
                $models[$id] = $entry;
            }
        }

        ksort($models);

        $result = new ScrapedPrices($models, array_merge([], ...array_values($this->changes)), $newModels, $this->skipped, $matched);

        $this->changes = [];
        $this->skipped = [];
        $this->page = null;

        return $result;
    }

    /**
     * Normalize the rows (cents, catalog ids) and merge duplicates: the first
     * page or row that gives a price wins.
     *
     * @param  array<string, ModelDefinition>  $definitions
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, ScrapedModel>
     */
    protected function group(ProviderPricing $pricing, array $definitions, array $rows, ?PageText $page): array
    {
        $lookup = new PricingLookup([$pricing]);
        $shape = CatalogShape::of($pricing);
        $displayNames = [];
        $byDisplayName = [];
        $byWords = [];
        foreach ($definitions as $id => $definition) {
            $name = $definition->toModelArray()['display_name'];
            $words = [$this->words($id)];

            if (is_string($name)) {
                $displayNames[$id] = $name;
                $byDisplayName[strtolower($name)] ??= $id;
                $words[] = $this->words($name);
            }

            foreach (array_unique($words) as $key) {
                // Two models with the same words can't be told apart: match neither.
                $byWords[$key] = isset($byWords[$key]) && $byWords[$key] !== $id ? false : $id;
            }
        }

        $grouped = [];
        $ignoredTiers = [];
        $ignoredFields = [];

        foreach ($rows as $row) {
            $rawId = trim((string) (is_scalar($row['model_id'] ?? null) ? $row['model_id'] : ''));
            $displayName = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : null;

            $id = $lookup->canonicalModel($pricing->provider(), $rawId)
                ?? $lookup->canonicalModel($pricing->provider(), strtolower($rawId))
                ?? $byDisplayName[strtolower($rawId)]
                ?? ($displayName !== null ? $byDisplayName[strtolower($displayName)] ?? null : null)
                ?: ($byWords[$this->words($rawId)] ?? null)
                ?: ($displayName !== null ? $byWords[$this->words($displayName)] ?? null : null)
                ?: (preg_match(self::MODEL_ID_PATTERN, $rawId) === 1 ? $rawId : null);

            if ($id === null) {
                $this->skipped[] = "'{$rawId}' is not a model id.";

                continue;
            }

            $tier = PricingTier::tryFrom(strtolower((string) (is_scalar($row['tier'] ?? null) ? $row['tier'] : 'standard')));

            if ($tier === null) {
                $this->skipped[] = "{$id}: unknown tier '".(is_scalar($row['tier']) ? $row['tier'] : '')."'.";

                continue;
            }

            $catalogTier = $shape->resolveTier($tier->value);

            if ($catalogTier === null) {
                $ignoredTiers[$tier->value] = ($ignoredTiers[$tier->value] ?? 0) + 1;

                continue;
            }

            $above = is_numeric($row['above_input_tokens'] ?? null) && $row['above_input_tokens'] > 0
                ? (int) $row['above_input_tokens']
                : null;

            $names = array_values(array_filter([$rawId, $id, $displayNames[$id] ?? null, $displayName]));
            $unit = is_string($row['unit'] ?? null) ? PricingUnit::tryFrom($row['unit'])?->value : null;
            $catalogUnit = isset($definitions[$id]) ? $definitions[$id]->toModelArray()['pricing_unit'] : null;
            $pricedPer = is_numeric($row['priced_per'] ?? null) && $row['priced_per'] > 0 ? (float) $row['priced_per'] : null;
            $prices = [];
            $scales = [];
            $unconfirmed = [];
            foreach (CatalogShape::FIELDS as $source => $field) {
                $value = $row[$source] ?? null;

                if (! is_numeric($value) || $value < 0) {
                    continue;
                }

                if (! $shape->allowsField($field)) {
                    $ignoredFields[$field] = ($ignoredFields[$field] ?? 0) + 1;

                    continue;
                }

                if ($field === 'price_per_unit' && ($catalogUnit ?? $unit) === PricingUnit::Characters->value && $pricedPer !== null && $pricedPer < 1000) {
                    $this->skipped[] = "{$id}: a per-character price of {$value} per {$pricedPer} characters can't be right, ignored.";

                    continue;
                }

                $line = $page?->find($names, (float) $value);

                if ($page !== null && $line === null) {
                    $unconfirmed[] = $this->label($field).' '.$value;

                    continue;
                }

                $fieldUnit = $catalogUnit ?? $unit;
                $perQuantity = $field !== 'price_per_unit' || $fieldUnit === PricingUnit::Characters->value;

                if ($line !== null && $perQuantity && ! $this->quantityMatches($pricedPer, PageText::quantityOn($line))) {
                    $this->skipped[] = "{$id}: {$this->label($field)} {$value} is priced per ".number_format($pricedPer ?? 1_000_000).', but the page line says otherwise, ignored.';

                    continue;
                }

                $scales[$field] = $this->scale($field, $fieldUnit, $pricedPer);
                $prices[$field] = round((float) $value * $scales[$field] * 100, 6);
            }

            if ($unconfirmed !== []) {
                $this->skipped[] = "{$id}: {$this->describeRow($catalogTier, $above)} ".implode(', ', $unconfirmed).' not found next to the model on the page, ignored.';
            }

            if ($prices === []) {
                continue;
            }

            $grouped[$id] ??= ['display_name' => $displayName, 'unit' => $unit, 'names' => [], 'rows' => []];
            $grouped[$id]['unit'] ??= $unit;
            $grouped[$id]['names'] = array_values(array_unique([...$grouped[$id]['names'], ...$names]));

            $key = $catalogTier.'|'.$above;
            $grouped[$id]['rows'][$key] ??= ['tier' => $catalogTier, 'above' => $above, 'prices' => [], 'scales' => []];
            $grouped[$id]['rows'][$key]['prices'] += $prices;
            $grouped[$id]['rows'][$key]['scales'] += $scales;
        }

        foreach ($ignoredTiers as $tier => $count) {
            $this->skipped[] = "Ignored {$count} rows in the '{$tier}' tier: the {$pricing->provider()} catalog doesn't use it.";
        }

        foreach ($ignoredFields as $field => $count) {
            $this->skipped[] = "Ignored {$count} {$this->label($field)} prices: the {$pricing->provider()} catalog doesn't use them.";
        }

        return $grouped;
    }

    /**
     * What turns a printed price into the catalog's basis: per 1M tokens or
     * characters, or per single unit. Without `priced_per`, token prices are
     * taken as per 1M and unit prices as per unit.
     */
    protected function scale(string $field, ?string $unit, ?float $pricedPer): float
    {
        if ($field !== 'price_per_unit' || $unit === PricingUnit::Characters->value) {
            return 1_000_000 / ($pricedPer ?? 1_000_000);
        }

        return 1 / ($pricedPer ?? 1);
    }

    /**
     * Without `priced_per`, prices are taken as per 1M: fine unless the line
     * says per 1K. A stated quantity must match what the line says, when it
     * says anything.
     */
    protected function quantityMatches(?float $pricedPer, ?int $onLine): bool
    {
        return $onLine === null || (int) ($pricedPer ?? 1_000_000) === $onLine;
    }

    /**
     * A model name as a sorted set of words, so "Claude Haiku 3.5",
     * `claude-haiku-3-5` and `claude-3-5-haiku` are the same key (and
     * `embed-5.0` matches `embed-v5.0`).
     */
    protected function words(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', (string) preg_replace('/\bv(?=\d)/', '', strtolower($name)), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
    }

    public function label(string $field): string
    {
        return $field === 'price_per_unit' ? 'per unit' : str_replace('_', ' ', substr($field, 0, -strlen('_price')));
    }

    /**
     * @param  ScrapedModel  $scraped
     * @return array<string, mixed>|null The model's JSON entry, or null when nothing changed.
     */
    protected function applyToKnownModel(string $id, ModelDefinition $definition, array $scraped, string $today): ?array
    {
        $unit = $definition->toModelArray()['pricing_unit'];
        /** @var list<array<string, mixed>> $tiers */
        $tiers = $definition->toTiersArray();
        $changed = false;

        if ($unit !== PricingUnit::Tokens->value && $scraped['unit'] !== null && $scraped['unit'] !== $unit) {
            $this->skipped[] = "{$id}: the page prices it per {$scraped['unit']}, the catalog per {$unit}.";

            return null;
        }

        foreach ($scraped['rows'] as $row) {
            $prices = $this->pricesForUnit($row['prices'], $unit);

            if ($prices === []) {
                $this->skipped[] = "{$id}: the page has no ".($unit === PricingUnit::Tokens->value ? 'token prices' : "per-{$unit} price")." for {$this->describeRow($row['tier'], $row['above'])}.";

                continue;
            }

            $index = $this->rowInEffect($tiers, $row['tier'], $row['above'], $today);

            if ($index === null) {
                $newRow = $this->newTierRow($id, $row, $prices, $unit);
                $problem = $newRow !== null ? $this->implausible($newRow, $tiers, $today) : null;

                if ($problem !== null) {
                    $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} {$problem}, not added.";
                } elseif ($newRow !== null) {
                    $tiers[] = $newRow;
                    $this->recordNewRow($id, $row, $prices, $unit);
                    $changed = true;
                }

                continue;
            }

            $mixUp = $this->columnMixUp($tiers[$index], $prices);

            if ($mixUp !== null) {
                $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} prices look like they come from other columns ({$mixUp}), kept ours.";

                continue;
            }

            $updated = $tiers[$index];
            $pending = [];

            foreach ($prices as $field => $new) {
                $old = is_numeric($tiers[$index][$field] ?? null) ? (float) $tiers[$index][$field] : null;

                if ($old !== null && abs($old - $new) < 0.000001) {
                    continue;
                }

                if ($old !== null && $old > 0 && ($new / $old >= self::OUTLIER_RATIO || $new / $old <= 1 / self::OUTLIER_RATIO)) {
                    $this->skipped[] = "{$id}: {$field} for {$this->describeRow($row['tier'], $row['above'])} looks wrong ({$old} → {$new} cents), kept ours.";

                    continue;
                }

                if ($old !== null && $this->page?->confirms($scraped['names'], round($old / 100 / ($row['scales'][$field] ?? 1), 6))) {
                    $this->skipped[] = "{$id}: the page still shows our {$this->label($field)} price for {$this->describeRow($row['tier'], $row['above'])} next to the model, kept ours (".($new / 100).' is likely another column or tier).';

                    continue;
                }

                $updated[$field] = $new;
                $pending[] = new PriceChange($id, $row['tier'], $row['above'], $field, $old, $new, $unit);
            }

            if ($pending === []) {
                continue;
            }

            $problem = $this->implausible($updated, $tiers, $today);

            if ($problem !== null) {
                $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} {$problem}, kept ours.";

                continue;
            }

            $tiers[$index] = $updated;
            $this->changes[$id] = [...($this->changes[$id] ?? []), ...$pending];
            $changed = true;
        }

        if (! $changed) {
            return null;
        }

        $entry = ScrapedPricing::definitionToArray($definition);
        $entry['tiers'] = array_map(ScrapedPricing::tierToArray(...), $tiers);

        return $entry;
    }

    /**
     * @param  ScrapedModel  $scraped
     * @return array<string, mixed>|null
     */
    protected function newModel(string $id, array $scraped): ?array
    {
        $unit = $scraped['unit'] ?? PricingUnit::Tokens->value;
        $rows = [];

        foreach ($scraped['rows'] as $key => $row) {
            $tierRow = $this->newTierRow($id, $row, $this->pricesForUnit($row['prices'], $unit), $unit);

            if ($tierRow !== null) {
                $rows[$key] = $tierRow;
            }
        }

        $tiers = [];
        foreach ($rows as $key => $tierRow) {
            $problem = $this->implausible($tierRow, array_values($rows), '9999-12-31');

            if ($problem !== null) {
                $this->skipped[] = "{$id}: {$this->describeRow($tierRow['tier'], $tierRow['above_input_tokens'])} {$problem}, not added.";

                continue;
            }

            $tiers[$key] = $tierRow;
        }

        if (! in_array(PricingTier::Standard->value, array_column($tiers, 'tier'), true)) {
            $this->skipped[] = "{$id}: new model without a standard price, not added.";

            return null;
        }

        foreach ($tiers as $key => $tierRow) {
            $scrapedRow = $scraped['rows'][$key];
            $this->recordNewRow($id, $scrapedRow, $this->pricesForUnit($scrapedRow['prices'], $unit), $unit);
        }

        $tiers = array_map(ScrapedPricing::tierToArray(...), array_values($tiers));

        [$type, $capabilities] = match (PricingUnit::tryFrom($unit) ?? PricingUnit::Tokens) {
            PricingUnit::Tokens => ['text', ['text']],
            PricingUnit::Image, PricingUnit::Megapixel => ['image', ['images']],
            PricingUnit::Characters => ['audio', ['audio']],
            PricingUnit::Minute => ['audio', ['text']],
            PricingUnit::Second, PricingUnit::Video => ['video', ['video']],
            PricingUnit::Search, PricingUnit::Page, PricingUnit::Request => ['text', []],
        };

        return array_filter([
            'display_name' => $scraped['display_name'],
            'type' => $type,
            'pricing_unit' => $unit,
            'capabilities' => $capabilities,
            'tiers' => $tiers,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * A tier row the catalog doesn't have yet. It needs every price billing
     * relies on: input and output for token models, the unit price otherwise.
     *
     * @param  ScrapedRow  $row
     * @param  array<string, float>  $prices
     * @return TierEntry|null
     */
    protected function newTierRow(string $id, array $row, array $prices, string $unit): ?array
    {
        $required = $unit === PricingUnit::Tokens->value ? ['input_price', 'output_price'] : ['price_per_unit'];

        if (array_diff($required, array_keys($prices)) !== []) {
            $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} is missing ".implode(' or ', $required).', not added.';

            return null;
        }

        $tierRow = [
            'tier' => $row['tier'],
            'input_price' => $prices['input_price'] ?? 0.0,
            'output_price' => $prices['output_price'] ?? 0.0,
        ];
        foreach (array_slice(ScrapedPricing::PRICE_FIELDS, 2) as $field) {
            $tierRow[$field] = $prices[$field] ?? null;
        }
        $tierRow['above_input_tokens'] = $row['above'];
        $tierRow['since'] = null;

        return $tierRow;
    }

    /**
     * @param  ScrapedRow  $row
     * @param  array<string, float>  $prices
     */
    protected function recordNewRow(string $id, array $row, array $prices, string $unit): void
    {
        foreach ($prices as $field => $price) {
            $this->changes[$id][] = new PriceChange($id, $row['tier'], $row['above'], $field, null, $price, $unit);
        }
    }

    /**
     * Prices no provider charges: cached input above input, output below
     * input, or long-context prices below the regular ones. None of the
     * built-in catalog's rows do this, so a scraped row that does was read
     * from the wrong columns.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $tiers  The model's rows, for the long-context comparison.
     */
    protected function implausible(array $row, array $tiers, string $today): ?string
    {
        $input = (float) $row['input_price'];
        $output = (float) $row['output_price'];
        $cached = is_numeric($row['cached_input_price'] ?? null) ? (float) $row['cached_input_price'] : null;

        if ($cached !== null && $input > 0 && $cached > $input) {
            return 'has cached input above input';
        }

        if ($input > 0 && $output > 0 && $output < $input) {
            return 'has output below input';
        }

        if ($row['above_input_tokens'] !== null) {
            $base = $this->rowInEffect($tiers, (string) $row['tier'], null, $today);

            if ($base !== null && ($input < (float) $tiers[$base]['input_price'] || $output < (float) $tiers[$base]['output_price'])) {
                return 'is cheaper than the regular prices';
            }
        }

        return null;
    }

    /**
     * A scraped price that differs from ours but equals another price of the
     * same row was read from the wrong column (an Audio row's price taken as
     * the text input price, say). Returns a description of the first one.
     *
     * @param  array<string, mixed>  $row  The catalog row in effect.
     * @param  array<string, float>  $prices
     */
    protected function columnMixUp(array $row, array $prices): ?string
    {
        foreach ($prices as $field => $new) {
            $old = $row[$field] ?? null;

            if (is_numeric($old) && abs((float) $old - $new) < 0.000001) {
                continue;
            }

            foreach (ScrapedPricing::PRICE_FIELDS as $other) {
                $value = $row[$other] ?? null;

                if ($other !== $field && is_numeric($value) && abs((float) $value - $new) < 0.000001) {
                    return "{$this->label($field)} {$new} is our {$this->label($other)}";
                }
            }
        }

        return null;
    }

    /**
     * Token models take token prices, per-unit models take the unit price.
     *
     * @param  array<string, float>  $prices
     * @return array<string, float>
     */
    protected function pricesForUnit(array $prices, string $unit): array
    {
        $perUnit = $unit !== PricingUnit::Tokens->value;

        return array_filter($prices, fn (string $field) => ($field === 'price_per_unit') === $perUnit, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The index of the catalog row in effect today for a tier and threshold.
     *
     * @param  list<array<string, mixed>>  $tiers
     */
    protected function rowInEffect(array $tiers, string $tier, ?int $above, string $today): ?int
    {
        $match = null;

        foreach ($tiers as $index => $row) {
            if ($row['tier'] !== $tier || $row['above_input_tokens'] !== $above) {
                continue;
            }

            if ($row['since'] !== null && $row['since'] > $today) {
                continue;
            }

            if ($match === null || ($row['since'] ?? '') >= ($tiers[$match]['since'] ?? '')) {
                $match = $index;
            }
        }

        return $match;
    }

    protected function describeRow(string $tier, ?int $above): string
    {
        return $above !== null ? "{$tier} (> ".number_format($above).' tokens)' : $tier;
    }
}
