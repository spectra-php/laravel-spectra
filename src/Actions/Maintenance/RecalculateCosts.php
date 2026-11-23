<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsCostMultiplier;
use Spectra\Contracts\ExtractsCostMultiplierForModel;
use Spectra\Contracts\ReportsRequestCost;
use Spectra\Enums\PricingUnit;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Payload;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\Tracking\RequestContext;
use stdClass;

/**
 * Prices the stored requests again from the pricing catalog, with the usage
 * stored on each one and the price that applied on its date, then rebuilds
 * the daily stats of the stored days. It reads the catalog and writes only
 * the database: no pricing file is touched.
 *
 * A request's cost changes when the catalog has since been given a price for
 * its model, or a corrected one. A request keeps the cost it was recorded
 * with when the catalog can't say better:
 *
 * - its provider reported the cost itself (OpenRouter, xAI, DeepInfra);
 * - the catalog has no price for its model, tier or date;
 * - it was recorded in another reporting currency;
 * - its cost was made from something the row doesn't keep (the split of
 *   audio and image tokens or of 1-hour cache writes, megapixels, searches,
 *   a regional or per-variant surcharge), unless it was recorded at $0: an
 *   unpriced request is then priced from the usage the row does keep.
 */
class RecalculateCosts
{
    /** Requests read at a time. */
    private const CHUNK = 1000;

    /** The columns a cost is made from. Those a migration added later are read when they exist. */
    private const COLUMNS = [
        'id', 'provider', 'model', 'snapshot', 'pricing_tier', 'status_code', 'error_type',
        'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens', 'cache_write_tokens',
        'tool_call_counts', 'duration_seconds', 'input_characters', 'image_count', 'video_count', 'page_count',
        'prompt_cost', 'completion_cost', 'total_cost_in_cents', 'currency', 'created_at', 'started_at',
    ];

    /** Units billed by a quantity the row doesn't keep. */
    private const UNSTORED_UNITS = [PricingUnit::Megapixel, PricingUnit::Search];

    /**
     * Create a new recalculate costs action instance.
     */
    public function __construct(
        private readonly RequestCostCalculator $requestCosts,
        private readonly CostCalculator $costs,
        private readonly PricingLookup $lookup,
        private readonly ProviderRegistry $providers,
        private readonly RebuildStats $rebuildStats,
    ) {}

    /**
     * Recalculate the stored requests' costs and return how many were
     * checked, how many changed, and by how much the total moved, in cents.
     *
     * @return array{requests: int, updated: int, difference: float}
     */
    public function __invoke(): array
    {
        $result = ['requests' => 0, 'updated' => 0, 'difference' => 0.0];

        if (config('spectra.costs.enabled', true)) {
            $currency = Str::upper((string) config('spectra.costs.currency', 'USD'));
            $request = new SpectraRequest;
            $columns = array_values(array_intersect(self::COLUMNS, $request->getConnection()->getSchemaBuilder()->getColumnListing($request->getTable())));

            SpectraRequest::query()
                ->toBase()
                ->select($columns)
                ->whereIn('provider', $this->pricedProviders())
                ->chunkById(self::CHUNK, function (Collection $rows) use (&$result, $currency): void {
                    foreach ($rows as $row) {
                        $result['requests']++;
                        $cost = $this->recalculated($row, $currency);

                        if ($cost === null) {
                            continue;
                        }

                        SpectraRequest::query()->toBase()->where('id', $row->id)->update([
                            'prompt_cost' => $cost['prompt_cost'] ?? 0.0,
                            'completion_cost' => $cost['completion_cost'] ?? 0.0,
                            'total_cost_in_cents' => $cost['total_cost_in_cents'],
                        ]);

                        $result['updated']++;
                        $result['difference'] += $cost['total_cost_in_cents'] - Payload::float($row->total_cost_in_cents);
                    }
                });

            if ($result['updated'] > 0) {
                $this->rebuildStats->storedDays();
            }
        }

        // Costs are stored to six decimals; the sum of their changes has no more.
        $result['difference'] = round($result['difference'], 6);

        MaintenanceHistory::record(MaintenanceHistory::RECALCULATE_COSTS, $result);

        return $result;
    }

    /**
     * Get the providers with stored requests whose cost comes from a catalog.
     *
     * @return list<string>
     */
    protected function pricedProviders(): array
    {
        $providers = [];

        foreach (SpectraRequest::query()->toBase()->distinct()->pluck('provider') as $provider) {
            if (is_string($provider) && $this->lookup->hasCatalog($provider) && ! $this->providers->provider($provider) instanceof ReportsRequestCost) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Get the request's cost from the catalog, or null when it keeps the cost it has.
     *
     * @return array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}|null
     */
    protected function recalculated(stdClass $row, string $currency): ?array
    {
        $provider = (string) $row->provider;
        $model = $this->pricedModel($provider, $row);
        $recorded = Payload::float($row->total_cost_in_cents);

        if ($model === null || (filled($row->currency ?? null) && Str::upper((string) $row->currency) !== $currency)) {
            return null;
        }

        $context = $this->context($row, $model);
        $tier = filled($row->pricing_tier) ? (string) $row->pricing_tier : null;
        $price = $this->costs->getPricing($provider, $model, $tier, $context->promptTokens, $context->getStartedAt());

        if ($price === null || (! $this->rowHoldsEveryInput($row, $provider, $model, $price) && $recorded > 0.0)) {
            return null;
        }

        $cost = $this->requestCosts->forContext($context, $tier);

        return $this->differs($cost['total_cost_in_cents'], $recorded)
            || $this->differs($cost['prompt_cost'] ?? 0.0, Payload::float($row->prompt_cost))
            || $this->differs($cost['completion_cost'] ?? 0.0, Payload::float($row->completion_cost))
            ? $cost
            : null;
    }

    /**
     * Get the model id the catalog prices the request by: the provider's id
     * for it when the catalog knows that, else the stored model.
     */
    protected function pricedModel(string $provider, stdClass $row): ?string
    {
        foreach ([$row->snapshot, $row->model] as $model) {
            if (is_string($model) && $model !== '' && $this->lookup->knows($provider, $model)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Determine if the row keeps everything the model's cost is made from,
     * so the recalculated cost is exact.
     *
     * @param  array<string, mixed>  $price
     */
    protected function rowHoldsEveryInput(stdClass $row, string $provider, string $model, array $price): bool
    {
        $instance = $this->providers->provider($provider);

        // A surcharge read from the request or the response isn't on the row.
        if ($instance instanceof ExtractsCostMultiplier || $instance instanceof ExtractsCostMultiplierForModel) {
            return false;
        }

        $unit = PricingUnit::tryFrom($this->costs->getPricingUnit($provider, $model)) ?? PricingUnit::Tokens;

        if ($unit !== PricingUnit::Tokens) {
            return ! in_array($unit, self::UNSTORED_UNITS, true);
        }

        // Audio and image tokens are stored inside the totals, not apart.
        foreach (['audio_input' => 'input', 'image_input' => 'input', 'audio_output' => 'output', 'image_output' => 'output'] as $kind => $base) {
            if (($price[$kind] ?? null) !== null && $price[$kind] !== $price[$base]) {
                return false;
            }
        }

        $fiveMinuteWrite = $price['cache_write_5m'] ?? $price['input'];

        // Nor is the share of cache writes kept for an hour.
        return Payload::int($row->cache_write_tokens ?? 0) === 0 || ($price['cache_write_1h'] ?? $fiveMinuteWrite) === $fiveMinuteWrite;
    }

    /**
     * Build the request context a stored row was recorded from, as far as the row keeps it.
     */
    protected function context(stdClass $row, string $model): RequestContext
    {
        $toolCalls = is_string($row->tool_call_counts ?? null) ? json_decode($row->tool_call_counts, true) : null;

        return new RequestContext([
            'started_at' => Date::parse($row->started_at ?? $row->created_at),
            'provider' => (string) $row->provider,
            'model' => $model,
            'prompt_tokens' => Payload::int($row->prompt_tokens),
            'completion_tokens' => Payload::int($row->completion_tokens),
            'reasoning_tokens' => Payload::int($row->reasoning_tokens),
            'cached_tokens' => Payload::int($row->cached_tokens ?? 0),
            'cache_write_tokens' => Payload::int($row->cache_write_tokens ?? 0),
            'tool_call_counts' => is_array($toolCalls) ? $toolCalls : [],
            'duration_seconds' => $row->duration_seconds !== null ? Payload::float($row->duration_seconds) : null,
            'input_characters' => $row->input_characters !== null ? Payload::int($row->input_characters) : null,
            'image_count' => $row->image_count !== null ? Payload::int($row->image_count) : null,
            'video_count' => $row->video_count !== null ? Payload::int($row->video_count) : null,
            'page_count' => ($row->page_count ?? null) !== null ? Payload::int($row->page_count) : null,
            'http_status' => $row->status_code !== null ? Payload::int($row->status_code) : null,
            'error_type' => $row->error_type,
        ]);
    }

    /**
     * Determine if two costs differ at the precision they are stored with.
     */
    protected function differs(float $a, float $b): bool
    {
        return abs($a - $b) >= 0.000_000_5;
    }
}
