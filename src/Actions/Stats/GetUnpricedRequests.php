<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Spectra\Data\UnpricedRequests;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The overview's count of successful $0 requests whose model has no price in
 * its provider's catalog. A model without a price is recorded at $0 like a
 * free one, so the spend total is short by every such call; this says how
 * many and which models. Providers without a catalog (Ollama) are free on
 * purpose and are left out.
 */
class GetUnpricedRequests
{
    /** Models named in the response. */
    private const MODELS = 5;

    /**
     * Create a new get unpriced requests action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * Get the successful $0 requests of models without a price, and those models.
     */
    public function __invoke(DateRange $dateRange, string $layout): UnpricedRequests
    {
        $unpriced = RequestAggregateQuery::fromRollups($dateRange)
            ->forLayout($layout)
            ->successfulOnly()
            ->groupByColumns('provider', 'model')
            ->countAs()
            ->orderByDesc('count')
            ->withoutCost()
            ->get()
            ->filter(fn (SpectraRequest $row) => $this->pricingLookup->isUnpriced($row->provider, $row->model));

        return new UnpricedRequests(
            request_count: (int) $unpriced->sum('count'),
            models: array_values($unpriced->take(self::MODELS)->map(fn (SpectraRequest $row): string => $row->model)->all()),
        );
    }
}
