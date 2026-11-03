<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One row of the costs screen's per-model table: requests (failures
 * included), usage, the cost of successful requests and what the model can
 * do, from the pricing catalog.
 */
readonly class CostByModel extends DataTransferObject
{
    public function __construct(
        public string $model,
        public ?string $provider,
        public string $model_type,
        public int $requests,
        public int $tokens,
        public int $images,
        public int $videos,
        public int $input_characters,
        public float $duration_seconds,
        public float $cost,
        /** @var array<string, bool> */
        public array $capabilities,
    ) {}
}
