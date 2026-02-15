<?php

namespace Spectra\Data;

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
