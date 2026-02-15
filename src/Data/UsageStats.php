<?php

namespace Spectra\Data;

readonly class UsageStats extends DataTransferObject
{
    public function __construct(
        public int $requestCount = 0,
        public int $successfulCount = 0,
        public int $failedCount = 0,
        public float $successRate = 0,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $totalCostInCents = 0,
        public float $totalCost = 0,
        public float $avgLatencyMs = 0,
        public int $minLatencyMs = 0,
        public int $maxLatencyMs = 0,
    ) {}

}
