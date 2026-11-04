<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Data\ScoreEntry;

/**
 * One trace: its totals, its calls in the order they started, the spans
 * around them, the models it used, its tags and trackable. The trace screen
 * shows all of it; the request screen shows the totals and calls in its
 * Trace card.
 */
readonly class TraceDetailsResponse extends DataTransferObject
{
    /**
     * Create a new trace details response instance.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<int, array<string, mixed>>  $spans
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>|null  $trackable
     * @param  array<int, ScoreEntry>  $scores
     */
    public function __construct(
        public string $trace_id,
        public ?string $parent_span_id,
        public ?string $origin,
        public int $request_count,
        public int $failed_count,
        public ?int $position,
        public float $total_cost_in_cents,
        public int $total_tokens,
        public ?string $started_at,
        public int $duration_ms,
        public int $timeline_ms,
        public bool $truncated,
        public array $requests,
        public array $spans,
        public array $models,
        public array $tags,
        public ?array $trackable,
        public array $scores = [],
    ) {}
}
