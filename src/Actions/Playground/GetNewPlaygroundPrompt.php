<?php

declare(strict_types=1);

namespace Spectra\Actions\Playground;

use Illuminate\Support\Str;
use Spectra\Data\Responses\PlaygroundCallResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Playground\PlaygroundClient;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\ProviderRegistry;

/**
 * What the Playground's editor opens for a new prompt: the chosen provider's
 * starting body and models. With no provider chosen it starts from the one the
 * app used last, so the screen opens on a provider that has a key and a model
 * the app really uses. A session ties a new prompt's runs together, since they
 * have no stored call to point back to.
 */
class GetNewPlaygroundPrompt
{
    /**
     * Create a new get new playground prompt action instance.
     */
    public function __construct(
        private readonly PlaygroundClient $client,
        private readonly PricingLookup $pricingLookup,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * Get the new prompt for the provider, or for the default one when it is null.
     */
    public function __invoke(?string $provider): PlaygroundCallResponse
    {
        $provider ??= $this->defaultProvider();

        return new PlaygroundCallResponse(
            call: null,
            provider: $provider,
            endpoint: $this->client->newPromptEndpoint($provider),
            body: $this->client->newPromptBody($provider),
            model: $this->lastUsedModel($provider) ?? '',
            models: $this->pricingLookup->textModels($provider),
            base_url: $this->client->baseUrl($provider),
            notices: [],
            unavailable_reason: $this->client->missingKeyReason($provider),
            providers: array_map(fn (string $slug): array => [
                'slug' => $slug,
                'name' => $this->providers->displayName($slug),
                'has_key' => $this->client->hasKey($slug),
            ], $this->client->providers()),
            session: (string) Str::uuid7(),
        );
    }

    /**
     * Get the provider of the app's latest text call that has a key, or else
     * the first provider that has one.
     */
    private function defaultProvider(): string
    {
        $withKeys = array_values(array_filter($this->client->providers(), $this->client->hasKey(...)));

        $lastUsed = SpectraRequest::query()
            ->where('model_type', 'text')
            ->whereIn('provider', $withKeys)
            ->latest('created_at')
            ->value('provider');

        return is_string($lastUsed) ? $lastUsed : $withKeys[0];
    }

    /**
     * Get the provider's model id from the app's latest text call to it.
     */
    private function lastUsedModel(string $provider): ?string
    {
        $model = SpectraRequest::query()
            ->where('provider', $provider)
            ->where('model_type', 'text')
            ->whereNotNull('snapshot')
            ->latest('created_at')
            ->value('snapshot');

        return is_string($model) ? $model : null;
    }
}
