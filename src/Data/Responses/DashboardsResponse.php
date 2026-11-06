<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DashboardSummary;
use Spectra\Data\DataTransferObject;

/**
 * The shared dashboards screen: whether they are on, whether this user may
 * design them, whether their migration has run, the saved dashboards, and the trackable types stored so far.
 */
readonly class DashboardsResponse extends DataTransferObject
{
    /**
     * Create a new shared dashboards response instance.
     *
     * @param  Collection<int, DashboardSummary>  $dashboards
     * @param  array<int, string>  $trackable_types
     */
    public function __construct(
        public bool $enabled,
        public bool $allowed,
        public bool $migrated,
        public Collection $dashboards,
        public array $trackable_types,
    ) {}
}
