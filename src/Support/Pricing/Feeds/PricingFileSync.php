<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

/**
 * What one run of the price sync did to a pricing file.
 */
final class PricingFileSync
{
    /**
     * Create a new pricing file sync result.
     *
     * @param  array<string, ProviderSync>  $providers  Provider slug => what its feed rows changed.
     * @param  list<string>  $notices  Problems that didn't stop the run, such as models.dev being unreachable.
     * @param  bool  $changed  Whether the file differs from before, written or not (a dry run).
     * @param  int|null  $declined  The changes recorded as declined, when the run only declined them.
     * @param  int  $staleDeclines  Declined changes the feed no longer proposes.
     * @param  int  $models  The models the file holds after the run.
     */
    public function __construct(
        public readonly string $path,
        public readonly bool $updatesBundled,
        public readonly bool $fileExisted,
        public readonly array $providers,
        public readonly array $notices,
        public readonly bool $changed,
        public readonly bool $written,
        public readonly ?int $declined,
        public readonly int $staleDeclines,
        public readonly int $models,
    ) {}
}
