<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The totals above the conversations list, over every conversation the
 * filters keep (not just the page shown).
 */
readonly class ConversationsSummary extends DataTransferObject
{
    public function __construct(
        public int $conversation_count,
        public int $failed_conversation_count,
        public float $total_cost_in_cents,
        public float $avg_cost_in_cents,
        public float $avg_turn_count,
    ) {}
}
