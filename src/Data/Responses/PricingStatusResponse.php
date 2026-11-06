<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The maintenance screen's prices card: the bundled catalog, the app's own
 * pricing file when it has one, and whether the index is cached.
 */
readonly class PricingStatusResponse extends DataTransferObject
{
    /**
     * Create a new pricing status response instance.
     *
     * @param  array<string, mixed>  $bundled
     * @param  array<string, mixed>|null  $file
     * @param  array{enabled: bool, store: ?string}  $cache
     */
    public function __construct(
        public array $bundled,
        public ?array $file,
        public array $cache,
    ) {}
}
