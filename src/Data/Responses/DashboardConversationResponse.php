<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * A conversation on a shared dashboard: its totals and turns. A turn's message
 * and reply are null on a dashboard that hides messages, and a cost on one that
 * hides costs.
 */
readonly class DashboardConversationResponse extends DataTransferObject
{
    /**
     * Create a new dashboard conversation response instance.
     *
     * @param  array<int, array<string, mixed>>  $turns
     */
    public function __construct(
        public string $conversation_id,
        public int $request_count,
        public int $turn_count,
        public int $failed_count,
        public ?float $total_cost_in_cents,
        public int $total_tokens,
        public ?string $started_at,
        public ?string $last_activity_at,
        public bool $truncated,
        public array $turns,
    ) {}
}
