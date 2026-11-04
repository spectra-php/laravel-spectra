<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Everything a handler extracts from one provider response.
 */
readonly class Metrics extends DataTransferObject
{
    /**
     * Create a new metrics instance.
     *
     * @param  array<string, int>  $toolCalls  Billable server-side tool usage keyed by the
     *                                         provider's tool-pricing key (e.g. ['web_search' => 3]).
     */
    public function __construct(
        public ?TokenMetrics $tokens = null,
        public ?ImageMetrics $image = null,
        public ?AudioMetrics $audio = null,
        public ?VideoMetrics $video = null,
        public ?SearchMetrics $search = null,
        public ?DocumentMetrics $document = null,
        public array $toolCalls = [],
        /**
         * The cost the provider itself reported for this request, in US cents
         * (e.g. OpenRouter `usage.cost`, xAI `cost_in_usd_ticks`). When set it
         * replaces the catalog estimate.
         */
        public ?float $reportedCostInCents = null,
    ) {}

    /**
     * Get the usage parts the handler set as arrays, leaving out empty ones.
     */
    public function toArray(): array
    {
        return collect(parent::toArray())
            ->map(fn (mixed $value) => $value instanceof DataTransferObject ? $value->toArray() : $value)
            ->filter(fn (mixed $value) => $value !== null && $value !== [])
            ->all();
    }
}
