<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * A trace to review: where it came from, what each of its calls was asked
 * and answered, and the scores it has so far.
 */
readonly class AnnotationReviewItem extends DataTransferObject
{
    /**
     * Create a new annotation review item instance.
     *
     * @param  array<int, array<string, mixed>>  $calls  In the order they started
     * @param  array<int, ScoreEntry>  $scores  Newest first
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $trace_id,
        public ?string $request_id,
        public ?string $origin,
        public ?string $queued_at,
        public array $calls,
        public array $scores,
    ) {}
}
