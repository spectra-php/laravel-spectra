<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The maintenance screen's prices card: the bundled catalog, the app's own
 * pricing file when it has one, whether the index is cached, and the
 * recalculation of stored costs a manager can start from the card.
 */
readonly class PricingStatusResponse extends DataTransferObject
{
    /**
     * Create a new pricing status response instance.
     *
     * @param  array<string, mixed>  $bundled
     * @param  array<string, mixed>|null  $file
     * @param  array{enabled: bool, store: ?string}  $cache
     * @param  bool  $can_manage  Whether the current user may recalculate the stored requests' costs.
     * @param  bool  $runs_on_queue  Whether a recalculation runs as a queued job (`spectra.queue.enabled`) or during the request.
     * @param  string|null  $queue_name  The queue it is put on, when one is named.
     * @param  bool  $pending  Whether a recalculation is queued and has not finished.
     * @param  array<string, mixed>|null  $last_recalculation  The last recalculation: when, the requests checked and changed, and the difference in cents.
     */
    public function __construct(
        public array $bundled,
        public ?array $file,
        public array $cache,
        public bool $can_manage,
        public bool $runs_on_queue,
        public ?string $queue_name,
        public bool $pending,
        public ?array $last_recalculation,
    ) {}
}
