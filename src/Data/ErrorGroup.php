<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One group of similar failures in the period: what failed, how often, the
 * models it hit, when it was first and last seen, and its daily trend.
 */
readonly class ErrorGroup extends DataTransferObject
{
    /**
     * Create a new error group instance.
     *
     * @param  array<int, array{model: string, count: int}>  $models  Most affected first
     * @param  array<int, int>  $trend  Failures per day, oldest first
     */
    public function __construct(
        public string $fingerprint,
        public string $provider,
        public ?int $status_code,
        public ?string $error_type,
        public ?string $message,
        public string $latest_request_id,
        public int $failure_count,
        public float $share,
        public array $models,
        public ?string $first_seen_at,
        public ?string $last_seen_at,
        public array $trend,
    ) {}
}
