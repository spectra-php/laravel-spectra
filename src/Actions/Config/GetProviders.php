<?php

declare(strict_types=1);

namespace Spectra\Actions\Config;

use Spectra\Data\Provider;
use Spectra\Data\Responses\ProvidersResponse;
use Spectra\Support\ProviderRegistry;

/**
 * Every provider the registry knows, built in or user-registered, with its
 * display name.
 */
class GetProviders
{
    /**
     * Create a new get providers action instance.
     */
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    /**
     * Get every registered provider with its display name.
     */
    public function __invoke(): ProvidersResponse
    {
        $providers = collect($this->providerRegistry->slugs())
            ->map(fn (string $slug) => new Provider(
                internal_name: $slug,
                display_name: $this->providerRegistry->displayName($slug),
            ))->values();

        return new ProvidersResponse(providers: $providers);
    }
}
