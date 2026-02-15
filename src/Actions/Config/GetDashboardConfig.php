<?php

namespace Spectra\Actions\Config;

use Spectra\Data\Responses\DashboardConfigResponse;
use Spectra\Enums\ModelType;

class GetDashboardConfig
{
    public function __invoke(): DashboardConfigResponse
    {
        $layout = config('spectra.dashboard.layout', 'full');

        $modelTypes = collect(ModelType::cases())->map(fn (ModelType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ])->values();

        return new DashboardConfigResponse(
            layout: $layout,
            model_types: $modelTypes,
        );
    }
}
