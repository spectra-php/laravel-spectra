<?php

namespace Spectra\Actions\Config;

use Spectra\Data\Provider;
use Spectra\Data\Responses\ProvidersResponse;
use Spectra\Support\ProviderRegistry;

class GetProviders
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function __invoke(): ProvidersResponse
    {
        $providers = collect($this->providerRegistry->slugs())
            ->map(fn (string $slug) => new Provider(
                internal_name: $slug,
                display_name: $this->providerRegistry->displayName($slug),
                logo_svg: $this->providerRegistry->logoSvg($slug),
            ))->values();

        return new ProvidersResponse(providers: $providers);
    }
}
