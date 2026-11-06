<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DashboardWidgetData;
use Spectra\Data\DataTransferObject;

/**
 * A shared dashboard as its viewer sees it: its name, whose data it shows, the
 * period it covers and its widgets with their data.
 */
readonly class DashboardResponse extends DataTransferObject
{
    /**
     * Create a new shared dashboard response instance.
     *
     * @param  Collection<int, DashboardWidgetData>  $widgets
     */
    public function __construct(
        public string $name,
        public ?string $trackable_name,
        public string $period,
        public bool $show_costs,
        public Collection $widgets,
    ) {}
}
