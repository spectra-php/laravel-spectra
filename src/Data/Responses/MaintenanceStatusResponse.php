<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Data\PrunePreview;

/**
 * The maintenance screen: whether the user may act, whether recording is
 * paused, how much is stored, what a prune would delete, and the last runs.
 */
readonly class MaintenanceStatusResponse extends DataTransferObject
{
    /**
     * Create a new maintenance status response instance.
     *
     * @param  array{requests: int, spans: int, daily_stats: int, oldest_request_at: string|null}  $totals
     * @param  array<string, mixed>|null  $last_prune
     * @param  array<string, mixed>|null  $last_rebuild
     * @param  array{prune: bool, rebuild_stats: bool}  $pending
     */
    public function __construct(
        public bool $enabled,
        public bool $can_manage,
        public bool $recording_paused,
        public array $totals,
        public PrunePreview $preview,
        public ?array $last_prune,
        public ?array $last_rebuild,
        public array $pending,
    ) {}
}
