<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The failures on a shared dashboard, grouped by cause. A group's message is null
 * on a dashboard that hides messages.
 */
readonly class DashboardErrorsResponse extends DataTransferObject
{
    /**
     * Create a new dashboard errors response instance.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function __construct(
        public int $request_count,
        public int $failed_count,
        public float $error_rate,
        public array $groups,
    ) {}
}
