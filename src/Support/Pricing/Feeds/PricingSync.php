<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\ModelDefinition;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Applies a price feed's rows to a provider's catalog.
 *
 * A feed lists every id a provider answers to, so before the rows reach the
 * mapper (and its safeguards) this picks one set of rows per catalog
 * model, lets a new model in only when models.dev lists it at the same price,
 * and drops the changes that were reviewed and declined.
 */
class PricingSync
{
    /** Prices this close (relative) count as equal. */
    protected const TOLERANCE = 0.001;

    /** @var list<string> */
    protected array $warnings = [];

    /** @var array<string, true> The declined entries that held a change back. */
    protected array $usedDeclines = [];

    /**
     * Create a new pricing sync instance.
     */
    public function __construct(
        protected FeedPriceMapper $mapper,
    ) {}

    /**
     * Map the feed's rows onto the provider's catalog.
     *
     * @param  list<array<string, mixed>>  $rows  Rows from a feed, in USD per 1M tokens or per unit.
     * @param  array<string, float|null>  $declined  Declined key => the feed price that was declined, in cents (null for a model).
     * @param  ModelsDevPrices|null  $modelsDev  Null when models.dev couldn't be loaded: no model is added then.
     */
    public function sync(ProviderPricing $pricing, array $rows, array $declined = [], ?ModelsDevPrices $modelsDev = null): ProviderSync
    {
        $this->warnings = [];
        $this->usedDeclines = [];

        $definitions = [];
        foreach ($pricing->models() as $definition) {
            $definitions[$definition->getInternalName()] = $definition;
        }

        $selected = $this->select($pricing, $definitions, $rows, $declined, $modelsDev);
        $kept = $this->dropDeclinedPrices($pricing, $this->snapToCatalog($pricing, $definitions, $selected), $declined);
        $result = $this->mapper->map($pricing, $kept);

        return new ProviderSync(
            provider: $pricing->provider(),
            rows: count($rows),
            declined: count($this->usedDeclines),
            usedDeclines: array_keys($this->usedDeclines),
            result: new FeedPrices($result->models, $result->changes, $result->newModels, [...$this->warnings, ...$result->skipped], $result->matchedModels),
        );
    }

    /**
     * Get the entries that decline everything a sync result changes.
     *
     * @return array<string, float|null>
     */
    public function declineAll(string $provider, FeedPrices $result): array
    {
        $baseline = [];

        foreach ($result->newModels as $model) {
            $baseline[self::modelKey($provider, $model)] = null;
        }

        foreach ($result->changes as $change) {
            if (! in_array($change->model, $result->newModels, true)) {
                $baseline[self::priceKey($provider, $change->model, $change->tier, $change->aboveInputTokens, $change->field)] = $change->new;
            }
        }

        return $baseline;
    }

    /**
     * Get the key a declined new model is recorded under.
     */
    public static function modelKey(string $provider, string $model): string
    {
        return "{$provider}|{$model}|model";
    }

    /**
     * Get the key one declined price is recorded under.
     */
    public static function priceKey(string $provider, string $model, string $tier, ?int $above, string $field): string
    {
        $row = $above === null ? $tier : "{$tier}>{$above}";

        return "{$provider}|{$model}|{$row}|{$field}";
    }

    /**
     * Pick the rows to map: one feed id per catalog model, under the
     * catalog's id, then the new models that are confirmed and not declined.
     *
     * @param  array<string, ModelDefinition>  $definitions
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, float|null>  $declined
     * @return list<array<string, mixed>>
     */
    protected function select(ProviderPricing $pricing, array $definitions, array $rows, array $declined, ?ModelsDevPrices $modelsDev): array
    {
        $provider = $pricing->provider();
        $lookup = new PricingLookup([$pricing]);

        $known = [];
        $unknown = [];

        foreach ($rows as $row) {
            $feedId = (string) $row['model_id'];
            $id = $lookup->canonicalModel($provider, $feedId);

            if ($id === null) {
                $unknown[$feedId][] = $row;
            } else {
                $known[$id][$feedId][] = $row;
            }
        }

        $selected = [];

        foreach ($known as $id => $byFeedId) {
            foreach ($this->pickFeedId($id, $definitions[$id], $byFeedId) as $row) {
                $selected[] = [...$row, 'model_id' => $id];
            }
        }

        return [...$selected, ...$this->selectNewModels($provider, $unknown, $known, $declined, $modelsDev)];
    }

    /**
     * Get the rows of the feed id that stands for a catalog model: the
     * model's own id, else one of its aliases, else a dated snapshot.
     *
     * An alias the feed prices differently has probably moved to another
     * model (`mistral-large-latest` from Large 3 to Large 4), which only a
     * hand edit can fix, so it is reported.
     *
     * @param  array<int|string, list<array<string, mixed>>>  $byFeedId  Feed id => rows; a numeric id is an int key.
     * @return list<array<string, mixed>>
     */
    protected function pickFeedId(string $id, ModelDefinition $definition, array $byFeedId): array
    {
        $named = Arr::only($byFeedId, [$id, ...$definition->getAliases()]);
        $chosen = isset($named[$id]) ? $id : (string) (array_key_first($named) ?? array_key_first($byFeedId));
        $chosenRows = $byFeedId[$chosen] ?? [];

        foreach ($named as $feedId => $rows) {
            if ((string) $feedId !== $chosen && ! $this->samePrice($this->standardPrice($rows), $this->standardPrice($chosenRows))) {
                $this->warnings[] = "{$id}: the feed prices {$feedId} at {$this->describePrice($rows)} and {$chosen} at {$this->describePrice($chosenRows)}. One entry bills both, so one of them may have moved to another model: check the entry and its aliases by hand.";
            }
        }

        return $chosenRows;
    }

    /**
     * Get the rows of the models the catalog doesn't have that may be added:
     * listed on models.dev at the same price, and not declined before.
     *
     * @param  array<int|string, list<array<string, mixed>>>  $unknown  Feed id => rows; a numeric id is an int key.
     * @param  array<int|string, mixed>  $known  The catalog models the feed has, by catalog id.
     * @param  array<string, float|null>  $declined
     * @return list<array<string, mixed>>
     */
    protected function selectNewModels(string $provider, array $unknown, array $known, array $declined, ?ModelsDevPrices $modelsDev): array
    {
        if ($unknown === []) {
            return [];
        }

        if ($modelsDev === null) {
            $this->warnings[] = "models.dev couldn't be loaded to confirm new models, so none were added.";

            return [];
        }

        $selected = [];
        $unconfirmed = 0;

        foreach ($unknown as $feedId => $rows) {
            $feedId = (string) $feedId;
            // A dated snapshot of a model that is listed undated bills as that model.
            $undated = Str::replaceMatches('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $feedId) ?? $feedId;

            if ($undated !== $feedId && (isset($unknown[$undated]) || isset($known[$undated]))) {
                continue;
            }

            if (array_key_exists(self::modelKey($provider, $feedId), $declined)) {
                $this->usedDeclines[self::modelKey($provider, $feedId)] = true;

                continue;
            }

            $price = $this->standardPrice($rows);
            $listed = $modelsDev->find($provider, $feedId);

            if ($listed === null || $price[0] === null || ! $this->samePrice($price, [$listed['input'], $listed['output']])) {
                $unconfirmed++;

                continue;
            }

            foreach ($rows as $row) {
                $selected[] = [...$row, 'display_name' => $listed['name']];
            }
        }

        if ($unconfirmed > 0) {
            $this->warnings[] = "Left out {$unconfirmed} feed models the catalog doesn't have: models.dev doesn't list them at the same price.";
        }

        return $selected;
    }

    /**
     * Replace each feed price that is the catalog's price after rounding with
     * the catalog's own figure, so a rounded number is not reported as a change.
     *
     * The feed rounds: LiteLLM keeps per-second prices to a few digits.
     *
     * @param  array<string, ModelDefinition>  $definitions
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function snapToCatalog(ProviderPricing $pricing, array $definitions, array $rows): array
    {
        $shape = CatalogShape::of($pricing);

        foreach ($rows as $index => $row) {
            $tier = $shape->resolveTier((string) $row['tier']) ?? (string) $row['tier'];
            $above = $row['above_input_tokens'] ?? null;

            foreach (($definitions[$row['model_id']] ?? null)?->toTiersArray() ?? [] as $catalogRow) {
                if ($catalogRow['tier'] !== $tier || $catalogRow['above_input_tokens'] !== $above) {
                    continue;
                }

                foreach (CatalogShape::FIELDS as $rowField => $field) {
                    $ours = $catalogRow[$field] ?? null;

                    if (isset($row[$rowField]) && $ours !== null && $this->equals((float) $row[$rowField] * 100, $ours)) {
                        $rows[$index][$rowField] = $ours / 100;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Remove from the rows every price that was declined at this value.
     *
     * A declined price whose feed value has changed is kept, so the new
     * value is reviewed again.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, float|null>  $declined
     * @return list<array<string, mixed>>
     */
    protected function dropDeclinedPrices(ProviderPricing $pricing, array $rows, array $declined): array
    {
        if ($declined === []) {
            return $rows;
        }

        $shape = CatalogShape::of($pricing);

        foreach ($rows as $index => $row) {
            $tier = $shape->resolveTier((string) $row['tier']) ?? (string) $row['tier'];
            $above = is_int($row['above_input_tokens'] ?? null) ? $row['above_input_tokens'] : null;

            foreach (CatalogShape::FIELDS as $rowField => $field) {
                $key = self::priceKey($pricing->provider(), (string) $row['model_id'], $tier, $above, $field);

                if (isset($row[$rowField], $declined[$key]) && $this->equals(round((float) $row[$rowField] * 100, 6), $declined[$key])) {
                    unset($rows[$index][$rowField]);
                    $this->usedDeclines[$key] = true;
                }
            }
        }

        return $rows;
    }

    /**
     * Get the standard input and output price of a feed id's rows, or its
     * unit price twice for a model billed per unit.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: float|null, 1: float|null}
     */
    protected function standardPrice(array $rows): array
    {
        $standard = Arr::first($rows, fn (array $row) => $row['tier'] === 'standard' && ($row['above_input_tokens'] ?? null) === null);

        if (isset($standard['price_per_unit'])) {
            return [(float) $standard['price_per_unit'], (float) $standard['price_per_unit']];
        }

        return [
            isset($standard['input']) ? (float) $standard['input'] : null,
            isset($standard['output']) ? (float) $standard['output'] : null,
        ];
    }

    /**
     * Describe a feed id's standard price for a report.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function describePrice(array $rows): string
    {
        [$input, $output] = $this->standardPrice($rows);

        return '$'.($input ?? '?').' / $'.($output ?? '?');
    }

    /**
     * Determine if two input and output price pairs are the same prices.
     *
     * @param  array{0: float|null, 1: float|null}  $a
     * @param  array{0: float|null, 1: float|null}  $b
     */
    protected function samePrice(array $a, array $b): bool
    {
        foreach ([0, 1] as $index) {
            if ($a[$index] === null || $b[$index] === null) {
                if ($a[$index] !== $b[$index]) {
                    return false;
                }

                continue;
            }

            if (! $this->equals($a[$index], $b[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if two prices are equal within the tolerance.
     */
    protected function equals(float $a, float $b): bool
    {
        return abs($a - $b) <= max(abs($a), abs($b)) * self::TOLERANCE;
    }
}
