<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One call in a shared dashboard's recent requests: what ran, how much it used
 * and whether it worked. It never holds the call's id, prompt, response,
 * metadata or trace, and the cost is null on a dashboard that hides costs.
 */
readonly class DashboardRequest extends DataTransferObject
{
    /**
     * Create a new shared request instance.
     */
    public function __construct(
        public ?string $provider,
        public ?string $provider_name,
        public ?string $model,
        public ?string $model_type,
        public int $tokens,
        public int $images,
        public int $videos,
        public int $input_characters,
        public float $duration_seconds,
        public ?int $latency_ms,
        public bool $failed,
        public ?float $cost,
        public ?string $created_at,
    ) {}
}
