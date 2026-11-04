<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Calls, cost (2xx only) and tokens per model for a group of calls (a
 * trace, a conversation), most expensive first.
 */
class BuildModelBreakdown
{
    /**
     * @param  RequestAggregateQuery  $calls  scoped to the group, nothing selected yet
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(RequestAggregateQuery $calls): array
    {
        return $calls
            ->groupByColumns('provider', 'model')
            ->countAs('request_count')
            ->successfulCost()
            ->tokenSum('tokens')
            ->orderByDesc('cost')
            ->get()
            ->map(fn (SpectraRequest $row) => [
                'provider' => $row->provider,
                'provider_display_name' => $row->provider_display_name,
                'model' => $row->model,
                'request_count' => $row->request_count,
                'total_cost_in_cents' => $row->cost,
                'total_tokens' => $row->tokens,
            ])
            ->values()
            ->all();
    }
}
