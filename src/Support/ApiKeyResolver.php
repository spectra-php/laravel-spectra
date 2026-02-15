<?php

namespace Spectra\Support;

class ApiKeyResolver
{
    /** @var array<string, array<int, string>> */
    protected array $lookupChains = [
        'openai' => ['spectra.api_keys.openai', 'openai.api_key', 'services.openai.api_key', 'services.openai.key', 'prism.providers.openai.api_key', 'ai.providers.openai.key'],
        'anthropic' => ['spectra.api_keys.anthropic', 'anthropic.api_key', 'services.anthropic.api_key', 'services.anthropic.key', 'prism.providers.anthropic.api_key', 'ai.providers.anthropic.key'],
        'google' => ['spectra.api_keys.google', 'services.google.api_key', 'services.google.key', 'prism.providers.gemini.api_key', 'ai.providers.google.key'],
        'replicate' => ['spectra.api_keys.replicate', 'services.replicate.api_key', 'prism.providers.replicate.api_key', 'ai.providers.replicate.key'],
        'cohere' => ['spectra.api_keys.cohere', 'services.cohere.api_key', 'prism.providers.cohere.api_key', 'ai.providers.cohere.key'],
        'groq' => ['spectra.api_keys.groq', 'services.groq.api_key', 'prism.providers.groq.api_key', 'ai.providers.groq.key'],
        'xai' => ['spectra.api_keys.xai', 'services.xai.api_key', 'prism.providers.xai.api_key', 'ai.providers.xai.key'],
        'elevenlabs' => ['spectra.api_keys.elevenlabs', 'services.elevenlabs.api_key', 'prism.providers.elevenlabs.api_key', 'ai.providers.elevenlabs.key'],
        'openrouter' => ['spectra.api_keys.openrouter', 'services.openrouter.api_key', 'services.openrouter.key', 'prism.providers.openrouter.api_key', 'ai.providers.openrouter.key'],
    ];

    /** @var array<string, array<int, string>> */
    protected array $urlLookupChains = [
        'openai' => ['spectra.endpoint_urls.openai', 'openai.base_uri', 'services.openai.url', 'ai.providers.openai.url'],
        'anthropic' => ['spectra.endpoint_urls.anthropic', 'services.anthropic.url', 'ai.providers.anthropic.url'],
        'google' => ['spectra.endpoint_urls.google', 'services.google.url', 'ai.providers.google.url'],
        'replicate' => ['spectra.endpoint_urls.replicate', 'services.replicate.url', 'ai.providers.replicate.url'],
        'cohere' => ['spectra.endpoint_urls.cohere', 'services.cohere.url', 'ai.providers.cohere.url'],
        'groq' => ['spectra.endpoint_urls.groq', 'services.groq.url', 'ai.providers.groq.url'],
        'xai' => ['spectra.endpoint_urls.xai', 'services.xai.url', 'ai.providers.xai.url'],
        'elevenlabs' => ['spectra.endpoint_urls.elevenlabs', 'services.elevenlabs.url', 'ai.providers.elevenlabs.url'],
        'openrouter' => ['spectra.endpoint_urls.openrouter', 'services.openrouter.url', 'ai.providers.openrouter.url'],
        'ollama' => ['spectra.endpoint_urls.ollama', 'services.ollama.url', 'ai.providers.ollama.url'],
    ];

    public function resolve(string $provider): ?string
    {
        $chain = $this->lookupChains[$provider] ?? ["spectra.api_keys.{$provider}", "ai.providers.{$provider}.key"];

        foreach ($chain as $configKey) {
            $value = config($configKey);
            if (! empty($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve the base URL for a provider.
     *
     * Checks spectra.endpoint_urls, then provider-specific config, then
     * laravel-ai (ai.providers.*.url) as a fallback.
     */
    public function resolveBaseUrl(string $provider): ?string
    {
        $chain = $this->urlLookupChains[$provider] ?? ["spectra.endpoint_urls.{$provider}", "ai.providers.{$provider}.url"];

        foreach ($chain as $configKey) {
            $value = config($configKey);
            if (! empty($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get the host (with optional port) from a resolved base URL.
     *
     * Returns null if no custom URL is configured for the provider.
     */
    public function resolveHost(string $provider): ?string
    {
        $url = $this->resolveBaseUrl($provider);

        if ($url === null) {
            return null;
        }

        $parsed = parse_url($url);

        if (! isset($parsed['host'])) {
            return null;
        }

        $host = $parsed['host'];

        if (isset($parsed['port'])) {
            $host .= ':'.$parsed['port'];
        }

        return $host;
    }
}
