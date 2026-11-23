<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spectra\Contracts\Handler;
use Spectra\Enums\ModelType;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\ModelDefinition;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\ProviderRegistry;

/**
 * Compares a price feed's rows with a provider's catalog.
 *
 * Only models whose prices differ, and models the catalog doesn't have, end
 * up in the result. A changed model keeps every catalog row (long-context,
 * dated, other tiers) and only the prices of the row in effect today are
 * replaced, so the JSON entry is a complete replacement for the model.
 *
 * A feed is not the provider, so rows that look like mistakes are skipped and
 * reported: tiers and price fields the catalog doesn't use, ids that aren't
 * API ids, prices off by OUTLIER_RATIO or more, a price that is another
 * column of the same row, a per-unit price for a token model (or the
 * reverse), and new rows missing a price they need. A new model must also be
 * a kind the provider's handlers bill and (when billed per unit) cost within
 * OUTLIER_RATIO of the catalog's prices for that unit.
 *
 * A row is `model_id`, `tier`, `above_input_tokens`, the prices (`input`,
 * `output`, `cached_input`, ... or `price_per_unit`) as currency amounts for
 * `priced_per` tokens, characters or units, `unit` and `kind`.
 *
 * @phpstan-import-type TierEntry from ModelDefinition
 *
 * @phpstan-type FeedRow array{tier: string, above: int|null, prices: array<string, float>}
 * @phpstan-type FeedModel array{display_name: string|null, unit: string|null, kind: string|null, rows: array<string, FeedRow>}
 */
class FeedPriceMapper
{
    /** A feed price this many times higher or lower than ours is treated as a mistake. */
    public const OUTLIER_RATIO = 10;

    protected const MODEL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,199}$/';

    /** What a model generates, as a feed row's `kind` says. */
    public const KINDS = ['text', 'image', 'audio', 'video', '3d', 'other'];

    /** @var array<string, list<PriceChange>> */
    protected array $changes = [];

    /** @var list<string> */
    protected array $skipped = [];

    /** @var array<string, ModelDefinition> */
    protected array $definitions = [];

    /** @var list<string>|null The kinds the provider's handlers bill; null when any may be billed. */
    protected ?array $billableKinds = null;

    /**
     * Map a feed's rows onto the catalog, keeping only changed and new models.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  string|null  $today  Y-m-d; picks the dated row in effect (default: today).
     */
    public function map(ProviderPricing $pricing, array $rows, ?string $today = null): FeedPrices
    {
        $today ??= now()->format('Y-m-d');

        $definitions = [];
        foreach ($pricing->models() as $definition) {
            $definitions[$definition->getInternalName()] = $definition;
        }

        $this->definitions = $definitions;
        $this->billableKinds = $this->findBillableKinds($pricing->provider());

        $models = [];
        $newModels = [];
        $matched = 0;

        foreach ($this->group($pricing, $definitions, $rows) as $id => $feedModel) {
            if (isset($definitions[$id])) {
                $matched++;
                $entry = $this->applyToKnownModel($id, $definitions[$id], $feedModel, $today);
            } else {
                $entry = $this->buildNewModel($id, $feedModel);

                if ($entry !== null) {
                    $newModels[] = $id;
                }
            }

            if ($entry !== null) {
                $models[$id] = $entry;
            }
        }

        ksort($models);

        $result = new FeedPrices($models, array_merge([], ...array_values($this->changes)), $newModels, $this->skipped, $matched);

        $this->changes = [];
        $this->skipped = [];
        $this->definitions = [];
        $this->billableKinds = null;

        return $result;
    }

    /**
     * Normalize the rows (cents, catalog ids) and merge duplicates: the first
     * row that gives a price wins.
     *
     * @param  array<string, ModelDefinition>  $definitions
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, FeedModel>
     */
    protected function group(ProviderPricing $pricing, array $definitions, array $rows): array
    {
        $lookup = new PricingLookup([$pricing]);
        $shape = CatalogShape::of($pricing);

        $grouped = [];
        $ignoredTiers = [];
        $ignoredFields = [];

        foreach ($rows as $row) {
            $rawId = trim((string) (is_scalar($row['model_id'] ?? null) ? $row['model_id'] : ''));
            $displayName = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : null;

            $id = $lookup->canonicalModel($pricing->provider(), $rawId)
                ?? $lookup->canonicalModel($pricing->provider(), strtolower($rawId))
                ?? (Str::isMatch(self::MODEL_ID_PATTERN, $rawId) ? $rawId : null);

            if ($id === null) {
                $this->skipped[] = "'{$rawId}' is not a model id.";

                continue;
            }

            $tier = PricingTier::tryFrom(Str::lower((string) (is_scalar($row['tier'] ?? null) ? $row['tier'] : 'standard')));

            if ($tier === null) {
                $tierLabel = is_scalar($row['tier']) ? $row['tier'] : '';
                $this->skipped[] = "{$id}: unknown tier '{$tierLabel}'.";

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

            $unit = is_string($row['unit'] ?? null) ? PricingUnit::tryFrom($row['unit'])?->value : null;
            $catalogUnit = isset($definitions[$id]) ? $definitions[$id]->toModelArray()['pricing_unit'] : null;
            $pricedPer = is_numeric($row['priced_per'] ?? null) && $row['priced_per'] > 0 ? (float) $row['priced_per'] : null;
            [$prices, $ignored] = $this->extractRowPrices($row, $id, $shape, $catalogUnit ?? $unit, $pricedPer);

            foreach ($ignored as $field) {
                $ignoredFields[$field] = ($ignoredFields[$field] ?? 0) + 1;
            }

            if ($prices === []) {
                continue;
            }

            $kind = is_string($row['kind'] ?? null) && in_array(strtolower($row['kind']), self::KINDS, true) ? strtolower($row['kind']) : null;

            $grouped[$id] ??= ['display_name' => $displayName, 'unit' => $unit, 'kind' => $kind, 'rows' => []];
            $grouped[$id]['unit'] ??= $unit;
            $grouped[$id]['kind'] ??= $kind;

            $key = "{$catalogTier}|{$above}";
            $grouped[$id]['rows'][$key] ??= ['tier' => $catalogTier, 'above' => $above, 'prices' => []];
            $grouped[$id]['rows'][$key]['prices'] += $prices;
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
     * Get the row's prices in catalog cents, and the fields it prices that
     * the catalog doesn't use.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, float>, 1: list<string>}
     */
    protected function extractRowPrices(array $row, string $id, CatalogShape $shape, ?string $unit, ?float $pricedPer): array
    {
        $prices = [];
        $ignored = [];

        foreach (CatalogShape::FIELDS as $source => $field) {
            $value = $row[$source] ?? null;

            if (! is_numeric($value) || $value < 0) {
                continue;
            }

            if (! $shape->allowsField($field)) {
                $ignored[] = $field;

                continue;
            }

            if ($field === 'price_per_unit' && $unit === PricingUnit::Characters->value && $pricedPer !== null && $pricedPer < 1000) {
                $this->skipped[] = "{$id}: a per-character price of {$value} per {$pricedPer} characters can't be right, ignored.";

                continue;
            }

            $prices[$field] = round((float) $value * $this->getScale($field, $unit, $pricedPer) * 100, 6);
        }

        return [$prices, $ignored];
    }

    /**
     * Get the factor that turns a row's price into the catalog's basis: per
     * 1M tokens or characters, or per single unit.
     *
     * Without `priced_per`, token prices are taken as per 1M and unit prices
     * as per unit.
     */
    protected function getScale(string $field, ?string $unit, ?float $pricedPer): float
    {
        if ($field !== 'price_per_unit' || $unit === PricingUnit::Characters->value) {
            return 1_000_000 / ($pricedPer ?? 1_000_000);
        }

        return 1 / ($pricedPer ?? 1);
    }

    /**
     * Get the readable name of a tier price field, as reports show it.
     */
    public function label(string $field): string
    {
        return $field === 'price_per_unit' ? 'per unit' : Str::replace('_', ' ', Str::substr($field, 0, -Str::length('_price')));
    }

    /**
     * Apply a model's feed prices to its catalog rows, skipping rows that look mistaken.
     *
     * @param  FeedModel  $feedModel
     * @return array<string, mixed>|null The model's JSON entry, or null when nothing changed.
     */
    protected function applyToKnownModel(string $id, ModelDefinition $definition, array $feedModel, string $today): ?array
    {
        $unit = $definition->toModelArray()['pricing_unit'];
        /** @var list<array<string, mixed>> $tiers */
        $tiers = $definition->toTiersArray();
        $hasChanged = false;

        if ($unit !== PricingUnit::Tokens->value && $feedModel['unit'] !== null && $feedModel['unit'] !== $unit) {
            $this->skipped[] = "{$id}: the feed prices it per {$feedModel['unit']}, the catalog per {$unit}.";

            return null;
        }

        foreach ($feedModel['rows'] as $row) {
            $prices = $this->filterPricesForUnit($row['prices'], $unit);

            if ($prices === []) {
                $expectedPrices = $unit === PricingUnit::Tokens->value ? 'token prices' : "per-{$unit} price";
                $this->skipped[] = "{$id}: the feed has no {$expectedPrices} for {$this->describeRow($row['tier'], $row['above'])}.";

                continue;
            }

            $index = $this->findRowInEffect($tiers, $row['tier'], $row['above'], $today);

            if ($index === null) {
                $newRow = $this->buildNewTierRow($id, $row, $prices, $unit);
                $problem = $newRow !== null ? $this->findImplausibility($newRow, $tiers, $today) : null;

                if ($problem !== null) {
                    $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} {$problem}, not added.";
                } elseif ($newRow !== null) {
                    $tiers[] = $newRow;
                    $this->recordNewRow($id, $row, $prices, $unit);
                    $hasChanged = true;
                }

                continue;
            }

            $mixUp = $this->describeColumnMixUp($tiers[$index], $prices);

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

                $updated[$field] = $new;
                $pending[] = new PriceChange($id, $row['tier'], $row['above'], $field, $old, $new, $unit);
            }

            if ($pending === []) {
                continue;
            }

            // A feed has one flat price for a fal.ai video model; the catalog
            // keeps the model page's price per resolution and audio.
            if ($definition->getVariants() !== []) {
                $this->skipped[] = "{$id}: priced per option from its model page (variants), kept ours. Edit it by hand.";

                continue;
            }

            $problem = $this->findImplausibility($updated, $tiers, $today);

            if ($problem !== null) {
                $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} {$problem}, kept ours.";

                continue;
            }

            $tiers[$index] = $updated;
            $this->changes[$id] = [...($this->changes[$id] ?? []), ...$pending];
            $hasChanged = true;
        }

        if (! $hasChanged) {
            return null;
        }

        $entry = ProviderPricing::definitionToArray($definition);
        $entry['tiers'] = Arr::map($tiers, ProviderPricing::tierToArray(...));

        return $entry;
    }

    /**
     * Build the file entry for a model the catalog doesn't have, if it has a plausible standard price.
     *
     * @param  FeedModel  $feedModel
     * @return array<string, mixed>|null
     */
    protected function buildNewModel(string $id, array $feedModel): ?array
    {
        $unit = $feedModel['unit'] ?? PricingUnit::Tokens->value;

        if ($feedModel['kind'] !== null && $this->billableKinds !== null && ! in_array($feedModel['kind'], $this->billableKinds, true)) {
            $this->skipped[] = "{$id}: new {$feedModel['kind']} model, but no {$this->describeKinds()} handler bills it, not added.";

            return null;
        }

        $rows = [];

        foreach ($feedModel['rows'] as $key => $row) {
            $tierRow = $this->buildNewTierRow($id, $row, $this->filterPricesForUnit($row['prices'], $unit), $unit);

            if ($tierRow !== null) {
                $rows[$key] = $tierRow;
            }
        }

        $tiers = [];
        foreach ($rows as $key => $tierRow) {
            $problem = $this->findImplausibility($tierRow, array_values($rows), '9999-12-31');

            if ($problem !== null) {
                $this->skipped[] = "{$id}: {$this->describeRow($tierRow['tier'], $tierRow['above_input_tokens'])} {$problem}, not added.";

                continue;
            }

            $tiers[$key] = $tierRow;
        }

        if (! in_array(PricingTier::Standard->value, Arr::pluck($tiers, 'tier'), true)) {
            $this->skipped[] = "{$id}: new model without a standard price, not added.";

            return null;
        }

        if ($this->unitNeedsVariants($unit)) {
            $this->skipped[] = "{$id}: the catalog prices its per-{$unit} models per option from their model pages (variants), not added. Add it by hand.";

            return null;
        }

        $problem = $this->findUnitPriceOutlier($unit, $tiers);

        if ($problem !== null) {
            $this->skipped[] = "{$id}: {$problem}, not added.";

            return null;
        }

        foreach ($tiers as $key => $tierRow) {
            $feedRow = $feedModel['rows'][$key];
            $this->recordNewRow($id, $feedRow, $this->filterPricesForUnit($feedRow['prices'], $unit), $unit);
        }

        $tiers = collect($tiers)->values()->map(ProviderPricing::tierToArray(...))->all();

        [$type, $capabilities] = match (PricingUnit::tryFrom($unit) ?? PricingUnit::Tokens) {
            PricingUnit::Tokens => ['text', ['text']],
            PricingUnit::Image, PricingUnit::Megapixel => ['image', ['images']],
            PricingUnit::Characters => ['audio', ['audio']],
            PricingUnit::Minute => ['audio', ['text']],
            PricingUnit::Second, PricingUnit::Video => ['video', ['video']],
            PricingUnit::Search, PricingUnit::Page, PricingUnit::Request => ['text', []],
        };

        return Arr::where([
            'display_name' => $feedModel['display_name'],
            'type' => $type,
            'pricing_unit' => $unit,
            'capabilities' => $capabilities,
            'tiers' => $tiers,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * Get the kinds of model the provider's handlers bill, or null for a
     * provider Spectra doesn't know (a catalog of your own), where any may be.
     *
     * @return list<string>|null
     */
    protected function findBillableKinds(string $provider): ?array
    {
        $instance = app(ProviderRegistry::class)->provider($provider);

        if ($instance === null) {
            return null;
        }

        return array_values(collect($instance->handlers())
            ->map(fn (Handler $handler) => match ($handler->modelType()) {
                ModelType::Text, ModelType::Embedding => 'text',
                ModelType::Image => 'image',
                ModelType::Tts, ModelType::Stt => 'audio',
                ModelType::Video => 'video',
            })
            ->unique()
            ->all());
    }

    /**
     * Describe the kinds the provider's handlers bill, for a report.
     */
    protected function describeKinds(): string
    {
        return implode('/', $this->billableKinds ?? []);
    }

    /**
     * Determine if every catalog model billed in this unit has variants, so a
     * new one needs prices a feed doesn't have (fal.ai video).
     */
    protected function unitNeedsVariants(string $unit): bool
    {
        $sameUnit = collect($this->definitions)
            ->filter(fn (ModelDefinition $definition) => $definition->toModelArray()['pricing_unit'] === $unit);

        return $unit !== PricingUnit::Tokens->value
            && $sameUnit->isNotEmpty()
            && $sameUnit->every(fn (ModelDefinition $definition) => $definition->getVariants() !== []);
    }

    /**
     * Get why a new per-unit model's standard price is off the scale of the
     * catalog's prices for the same unit (Mistral's OCR priced "$4 per 1,000
     * pages" read as $4 a page), or null when it is in range or has nothing
     * to compare with.
     *
     * @param  array<array-key, array<string, mixed>>  $tiers
     */
    protected function findUnitPriceOutlier(string $unit, array $tiers): ?string
    {
        if ($unit === PricingUnit::Tokens->value) {
            return null;
        }

        $standard = collect($tiers)->firstWhere('tier', PricingTier::Standard->value);
        $price = is_numeric($standard['price_per_unit'] ?? null) ? (float) $standard['price_per_unit'] : 0.0;

        $catalogPrices = collect($this->definitions)
            ->filter(fn (ModelDefinition $definition) => $definition->toModelArray()['pricing_unit'] === $unit)
            ->map(fn (ModelDefinition $definition) => collect($definition->toTiersArray())->firstWhere('tier', PricingTier::Standard->value)['price_per_unit'] ?? null)
            ->filter(fn (?float $value) => $value !== null && $value > 0);

        if ($price <= 0 || $catalogPrices->isEmpty()) {
            return null;
        }

        $lowest = (float) $catalogPrices->min();
        $highest = (float) $catalogPrices->max();

        if ($price < $highest * self::OUTLIER_RATIO && $price > $lowest / self::OUTLIER_RATIO) {
            return null;
        }

        return "per-{$unit} price {$price} cents is far from the catalog's ({$lowest}–{$highest} cents)";
    }

    /**
     * Build a tier row the catalog doesn't have yet.
     *
     * It needs every price billing relies on: input and output for token
     * models, the unit price otherwise.
     *
     * @param  FeedRow  $row
     * @param  array<string, float>  $prices
     * @return TierEntry|null
     */
    protected function buildNewTierRow(string $id, array $row, array $prices, string $unit): ?array
    {
        $required = $unit === PricingUnit::Tokens->value ? ['input_price', 'output_price'] : ['price_per_unit'];

        if (! Arr::has($prices, $required)) {
            $missingFields = implode(' or ', $required);
            $this->skipped[] = "{$id}: {$this->describeRow($row['tier'], $row['above'])} is missing {$missingFields}, not added.";

            return null;
        }

        $tierRow = [
            'tier' => $row['tier'],
            'input_price' => $prices['input_price'] ?? 0.0,
            'output_price' => $prices['output_price'] ?? 0.0,
        ];
        foreach (array_slice(ProviderPricing::PRICE_FIELDS, 2) as $field) {
            $tierRow[$field] = $prices[$field] ?? null;
        }
        $tierRow['above_input_tokens'] = $row['above'];
        $tierRow['since'] = null;

        return $tierRow;
    }

    /**
     * Record every price of a newly added tier row as a change from nothing.
     *
     * @param  FeedRow  $row
     * @param  array<string, float>  $prices
     */
    protected function recordNewRow(string $id, array $row, array $prices, string $unit): void
    {
        foreach ($prices as $field => $price) {
            $this->changes[$id][] = new PriceChange($id, $row['tier'], $row['above'], $field, null, $price, $unit);
        }
    }

    /**
     * Get why a row's prices are ones no provider charges, or null when they are plausible.
     *
     * Implausible prices are cached input above input, output below input, or
     * long-context prices below the regular ones. None of the built-in
     * catalog's rows do this, so a feed row that does was read from the
     * wrong columns.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $tiers  The model's rows, for the long-context comparison.
     */
    protected function findImplausibility(array $row, array $tiers, string $today): ?string
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
            $base = $this->findRowInEffect($tiers, (string) $row['tier'], null, $today);

            if ($base !== null && ($input < (float) $tiers[$base]['input_price'] || $output < (float) $tiers[$base]['output_price'])) {
                return 'is cheaper than the regular prices';
            }
        }

        return null;
    }

    /**
     * Describe the first feed price that was read from the wrong column, or return null.
     *
     * A feed price that differs from ours but equals another price of the
     * same row was read from the wrong column (an Audio row's price taken as
     * the text input price, say).
     *
     * @param  array<string, mixed>  $row  The catalog row in effect.
     * @param  array<string, float>  $prices
     */
    protected function describeColumnMixUp(array $row, array $prices): ?string
    {
        foreach ($prices as $field => $new) {
            $old = $row[$field] ?? null;

            if (is_numeric($old) && abs((float) $old - $new) < 0.000001) {
                continue;
            }

            foreach (ProviderPricing::PRICE_FIELDS as $other) {
                $value = $row[$other] ?? null;

                if ($other !== $field && is_numeric($value) && abs((float) $value - $new) < 0.000001) {
                    return "{$this->label($field)} {$new} is our {$this->label($other)}";
                }
            }
        }

        return null;
    }

    /**
     * Keep the prices a model's unit bills with: token prices for token models, the unit price for per-unit models.
     *
     * @param  array<string, float>  $prices
     * @return array<string, float>
     */
    protected function filterPricesForUnit(array $prices, string $unit): array
    {
        $isPerUnit = $unit !== PricingUnit::Tokens->value;

        return Arr::where($prices, fn (float $price, string $field) => ($field === 'price_per_unit') === $isPerUnit);
    }

    /**
     * Get the index of the catalog row in effect today for a tier and threshold.
     *
     * @param  list<array<string, mixed>>  $tiers
     */
    protected function findRowInEffect(array $tiers, string $tier, ?int $above, string $today): ?int
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

    /**
     * Describe a tier row for a report, with its long-context threshold when it has one.
     */
    protected function describeRow(string $tier, ?int $above): string
    {
        if ($above === null) {
            return $tier;
        }

        $threshold = number_format($above);

        return "{$tier} (> {$threshold} tokens)";
    }
}
