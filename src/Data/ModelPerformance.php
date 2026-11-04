<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * How one model performed in the period: its calls, how many failed, and its
 * latency percentiles over successful calls (time to first token over
 * streamed ones).
 */
readonly class ModelPerformance extends DataTransferObject
{
    /**
     * Create a new model performance instance.
     */
    public function __construct(
        public string $provider,
        public string $model,
        public int $request_count,
        public int $failed_count,
        public float $error_rate,
        public ?int $latency_p50,
        public ?int $latency_p95,
        public ?int $latency_p99,
        public ?int $ttft_p50,
    ) {}
}
