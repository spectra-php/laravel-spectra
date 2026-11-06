<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One version of one prompt template in the period: its calls, failures,
 * cost, tokens and latency, and the scores its calls and traces got, so
 * versions can be compared side by side.
 */
readonly class PromptVersion extends DataTransferObject
{
    /**
     * Create a new prompt version instance.
     *
     * @param  array<int, array{name: string, type: string, count: int, average: float|null}>  $scores
     */
    public function __construct(
        public string $prompt_name,
        public ?string $prompt_version,
        public int $request_count,
        public int $failed_count,
        public float $error_rate,
        public float $total_cost_in_cents,
        public float $avg_cost_in_cents,
        public int $avg_tokens,
        public float $avg_latency_ms,
        public ?string $first_seen_at,
        public ?string $last_seen_at,
        public array $scores,
    ) {}
}
