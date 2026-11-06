<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One review queue on the Review screen: its scores, and how many traces
 * wait in it, were reviewed or were skipped.
 */
readonly class AnnotationQueueSummary extends DataTransferObject
{
    /**
     * Create a new annotation queue summary instance.
     *
     * @param  array<int, array{name: string, type: string, min: float|null, max: float|null, options: array<int, string>}>  $scores
     * @param  array<string, mixed>  $filter
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $scores,
        public float $sample,
        public array $filter,
        public int $pending_count,
        public int $completed_count,
        public int $skipped_count,
    ) {}
}
