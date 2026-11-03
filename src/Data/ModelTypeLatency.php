<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;

/**
 * One row of a "latency by model type" breakdown (overview, trackables list,
 * trackable detail): how many requests of the type ran and their average
 * latency over successful requests.
 */
readonly class ModelTypeLatency extends DataTransferObject
{
    public function __construct(
        public string $model_type,
        public string $label,
        public int $count,
        public float $avg_latency,
    ) {}

    /**
     * @param  Collection<int, ModelTypeStat>  $stats
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $stats): Collection
    {
        return $stats->map(fn (ModelTypeStat $stat) => new self(
            model_type: $stat->model_type,
            label: $stat->label,
            count: $stat->count,
            avg_latency: $stat->avg_latency,
        ));
    }
}
