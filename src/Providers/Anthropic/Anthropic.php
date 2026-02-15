<?php

namespace Spectra\Providers\Anthropic;

use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Providers\Anthropic\Handlers\MessageHandler;
use Spectra\Providers\Provider;

class Anthropic extends Provider implements ExtractsPricingTierFromResponse
{
    public function getProvider(): string
    {
        return 'anthropic';
    }

    public function getHosts(): array
    {
        return ['api.anthropic.com'];
    }

    public function handlers(): array
    {
        return [
            app(MessageHandler::class),
        ];
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        $serviceTier = $responseData['usage']['service_tier'] ?? null;

        return $serviceTier !== null ? $this->mapServiceTier($serviceTier) : null;
    }

    protected function mapServiceTier(string $serviceTier): string
    {
        return match ($serviceTier) {
            'priority' => 'priority',
            'default', 'auto' => config("spectra.costs.provider_settings.{$this->getProvider()}.default_tier", 'standard'),
            default => $serviceTier,
        };
    }
}
