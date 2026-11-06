<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One score name on the overview: how often it was given in the period and
 * what it says on average. Yes/no scores report the share of yes in
 * `average`; categories report their most common labels.
 */
readonly class ScoreSummary extends DataTransferObject
{
    /**
     * Create a new score summary instance.
     *
     * @param  array<int, array{label: string, count: int}>  $labels
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $count,
        public ?float $average,
        public ?float $minimum,
        public ?float $maximum,
        public array $labels,
    ) {}
}
