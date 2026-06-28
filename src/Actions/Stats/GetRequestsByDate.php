<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\RequestByDate;
use Spectra\Queries\RequestsByDateQuery;
use Spectra\Support\DateRange;

class GetRequestsByDate
{
    public function __construct(
        private readonly RequestsByDateQuery $query,
    ) {}

    /**
     * @return Collection<int, RequestByDate>
     */
    public function __invoke(DateRange $dateRange, string $layout): Collection
    {
        return RequestByDate::fromCollection(($this->query)($dateRange, $layout));
    }
}
