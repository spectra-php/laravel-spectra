<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Costs\GetCostOverview;
use Spectra\Actions\Costs\GetCostsByDate;
use Spectra\Actions\Costs\GetCostsByModel;
use Spectra\Actions\Costs\GetCostsByModelType;
use Spectra\Actions\Costs\GetCostsByProvider;
use Spectra\Actions\Costs\GetCostsByUser;
use Spectra\Actions\Costs\GetTotalCost;
use Spectra\Data\Responses\CostsResponse;
use Spectra\Support\DateRange;

class CostsController extends BaseApiController
{
    public function __invoke(
        Request $request,
        GetTotalCost $getTotalCost,
        GetCostOverview $getCostOverview,
        GetCostsByProvider $getCostsByProvider,
        GetCostsByModel $getCostsByModel,
        GetCostsByModelType $getCostsByModelType,
        GetCostsByDate $getCostsByDate,
        GetCostsByUser $getCostsByUser,
    ): CostsResponse {
        $dateRange = DateRange::fromRequest($request);

        return new CostsResponse(
            total_cost_in_cents: $getTotalCost($dateRange),
            cost_overview: $getCostOverview(),
            costs_by_provider: $getCostsByProvider($dateRange),
            costs_by_model: $getCostsByModel($dateRange),
            costs_by_model_type: $getCostsByModelType($dateRange),
            costs_by_date: $getCostsByDate($dateRange),
            costs_by_user: $getCostsByUser($dateRange),
        );
    }
}
