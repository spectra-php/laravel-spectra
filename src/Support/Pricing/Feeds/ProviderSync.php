<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

/**
 * One provider's feed rows compared with its catalog.
 */
final class ProviderSync
{
    /**
     * Create a new provider sync result.
     *
     * @param  int  $rows  The rows the feed has for the provider.
     * @param  int  $declined  The changes left out because they were declined.
     * @param  list<string>  $usedDeclines  The keys of the declined entries that left them out.
     */
    public function __construct(
        public readonly string $provider,
        public readonly int $rows,
        public readonly int $declined,
        public readonly array $usedDeclines,
        public readonly FeedPrices $result,
    ) {}
}
