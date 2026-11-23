<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

/**
 * What a price feed changes in a provider's catalog.
 */
final class FeedPrices
{
    /**
     * Create a new result of mapping a feed's rows onto a provider's catalog.
     *
     * @param  array<string, array<string, mixed>>  $models  Model id => pricing file entry, for changed and new models only.
     * @param  list<PriceChange>  $changes
     * @param  list<string>  $newModels
     * @param  list<string>  $skipped  Why feed rows were not used.
     */
    public function __construct(
        public readonly array $models,
        public readonly array $changes,
        public readonly array $newModels,
        public readonly array $skipped,
        public readonly int $matchedModels,
    ) {}
}
