<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Costs\GetCostModels;
use Spectra\Actions\Costs\GetCostOverview;
use Spectra\Actions\Costs\GetCostsByDate;
use Spectra\Actions\Costs\GetCostsByModel;
use Spectra\Actions\Costs\GetCostsByModelType;
use Spectra\Actions\Costs\GetCostsByProvider;
use Spectra\Actions\Costs\GetCostsByUser;
use Spectra\Actions\Costs\GetTotalCost;
use Spectra\Data\Responses\CostsResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/costs: the costs screen for the selected period, for every model or
 * for the one in `model` (and `provider`).
 */
class CostsController extends BaseApiController
{
    /**
     * Get the costs screen's totals and breakdowns for the selected period,
     * narrowed to one model when the request names one. The summary cards'
     * fixed periods and the model list always cover every model.
     */
    public function __invoke(
        Request $request,
        GetTotalCost $getTotalCost,
        GetCostOverview $getCostOverview,
        GetCostsByProvider $getCostsByProvider,
        GetCostsByModel $getCostsByModel,
        GetCostsByModelType $getCostsByModelType,
        GetCostsByDate $getCostsByDate,
        GetCostsByUser $getCostsByUser,
        GetCostModels $getCostModels,
    ): CostsResponse {
        $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
        ]);

        $dateRange = DateRange::fromRequest($request);
        $model = $request->string('model')->toString() ?: null;
        // Without a provider, the model counts whichever provider served it.
        $provider = $model === null ? null : ($request->string('provider')->toString() ?: null);

        return new CostsResponse(
            total_cost_in_cents: $getTotalCost($dateRange, $model, $provider),
            cost_overview: $getCostOverview(),
            costs_by_provider: $getCostsByProvider($dateRange, $model, $provider),
            costs_by_model: $getCostsByModel($dateRange, model: $model, provider: $provider),
            costs_by_model_type: $getCostsByModelType($dateRange, $model, $provider),
            costs_by_date: $getCostsByDate($dateRange, $model, $provider),
            costs_by_user: $getCostsByUser($dateRange, model: $model, provider: $provider),
            models: $getCostModels($dateRange),
        );
    }
}
