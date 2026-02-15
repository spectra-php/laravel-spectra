<?php

namespace Spectra\Support;

use Spectra\Providers\Provider;

/**
 * Central registry for AI providers.
 *
 * Built from config('spectra.providers'), this class instantiates each provider to extract
 * hosts and endpoints, providing host detection, endpoint matching (with {placeholder}
 * support), and request fingerprinting.
 *
 * Custom endpoint URLs configured via spectra.endpoint_urls or laravel-ai's ai.providers.*.url
 * are automatically included as additional hosts for provider detection. This allows requests
 * routed through proxies or gateways to be detected and tracked correctly.
 */
class ProviderRegistry
{
    /**
     * Normalized provider configurations.
     *
     * @var array<string, array{class: string, name: string, hosts: array<string>, endpoints: array<string>}>
     */
    protected array $providers;

    /**
     * Cached compiled host patterns (slug => regex[]).
     *
     * @var array<string, array<string>>|null
     */
    protected ?array $hostPatterns = null;

    /**
     * Cached compiled endpoint patterns (slug => regex[]).
     *
     * @var array<string, array<string>>|null
     */
    protected ?array $endpointPatterns = null;

    /**
     * @param  array<string, string>  $config  Raw providers config from spectra.php (slug => class)
     * @param  ApiKeyResolver|null  $apiKeyResolver  Resolver for discovering custom endpoint URLs
     */
    public function __construct(array $config, ?ApiKeyResolver $apiKeyResolver = null)
    {
        $this->providers = $this->normalize($config, $apiKeyResolver);
    }

    /**
     * Normalize config values to the structured format.
     *
     * Config supports two formats:
     *   - Flat: 'openai' => OpenAI::class
     *   - Structured: 'openai' => ['class' => OpenAI::class, 'name' => 'OpenAI']
     *
     * Each provider class is instantiated to extract hosts and endpoints.
     * Custom endpoint URLs are resolved and appended as additional hosts.
     *
     * @return array<string, array{class: string, name: string, hosts: array<string>, endpoints: array<string>}>
     */
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array{class: string, name: string, hosts: array<string>, endpoints: array<string>}>
     */
    protected function normalize(array $config, ?ApiKeyResolver $apiKeyResolver = null): array
    {
        $normalized = [];

        foreach ($config as $slug => $providerConfig) {
            // Support both flat (class string) and structured (array) formats
            if (is_array($providerConfig)) {
                $providerClass = $providerConfig['class'] ?? null;
                $configName = $providerConfig['name'] ?? null;
            } else {
                $providerClass = $providerConfig;
                $configName = null;
            }

            if (! is_string($providerClass) || ! class_exists($providerClass)) {
                continue;
            }

            /** @var Provider $instance */
            $instance = new $providerClass;

            $hosts = $instance->getHosts();

            if ($apiKeyResolver !== null) {
                $customHost = $apiKeyResolver->resolveHost($slug);

                if ($customHost !== null && ! in_array($customHost, $hosts)) {
                    $hosts[] = $customHost;
                }
            }

            $normalized[$slug] = [
                'class' => $providerClass,
                'name' => $configName ?? ucfirst($slug),
                'hosts' => $hosts,
                'endpoints' => $instance->getEndpoints(),
            ];
        }

        return $normalized;
    }

    /**
     * Detect AI provider from a request host.
     *
     * Converts {placeholder} patterns in host configs to regex for matching.
     * For example, '{resource}.openai.azure.com' matches 'myresource.openai.azure.com'.
     */
    public function detectProvider(string $host): ?string
    {
        $patterns = $this->getHostPatterns();

        return \array_find_key($patterns, fn (array $regexes) => \array_any($regexes, fn (string $regex) => (bool) preg_match($regex, $host)));

    }

    /**
     * Check if a path should be tracked for the given provider.
     *
     * Converts {placeholder} patterns in endpoint configs to regex for matching.
     * For example, '/v1beta/models/{model}:generateContent' matches
     * '/v1beta/models/gemini-3-flash-preview:generateContent'.
     */
    public function isTrackableEndpoint(string $provider, string $path): bool
    {
        $patterns = $this->getEndpointPatterns();

        if (! isset($patterns[$provider])) {
            return false;
        }

        foreach ($patterns[$provider] as $regex) {
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    public function provider(string $provider): ?Provider
    {
        $key = "spectra.provider.{$provider}";

        if (app()->bound($key)) {
            return app($key);
        }

        return null;
    }

    public function displayName(string $provider): string
    {
        return $this->providers[$provider]['name'] ?? ucfirst($provider);
    }

    public function logoSvg(string $provider): ?string
    {
        $path = __DIR__.'/../../resources/images/logos/'.$provider.'.svg';

        if (! file_exists($path)) {
            return null;
        }

        $svg = file_get_contents($path);

        return $svg !== false ? $svg : null;
    }

    public function slugForProvider(string $providerClass): ?string
    {
        foreach ($this->providers as $slug => $config) {
            if ($config['class'] === $providerClass) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{class: string, name: string, hosts: array<string>, endpoints: array<string>}>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return array<string> */
    public function slugs(): array
    {
        return array_keys($this->providers);
    }

    /** @return array<string, array<string>> */
    protected function getHostPatterns(): array
    {
        if ($this->hostPatterns === null) {
            $this->hostPatterns = [];

            foreach ($this->providers as $slug => $config) {
                $this->hostPatterns[$slug] = [];

                foreach ($config['hosts'] as $host) {
                    $this->hostPatterns[$slug][] = $this->hostPatternToRegex($host);
                }
            }
        }

        return $this->hostPatterns;
    }

    /** @return array<string, array<string>> */
    protected function getEndpointPatterns(): array
    {
        if ($this->endpointPatterns === null) {
            $this->endpointPatterns = [];

            foreach ($this->providers as $slug => $config) {
                $this->endpointPatterns[$slug] = [];

                foreach ($config['endpoints'] as $endpoint) {
                    $this->endpointPatterns[$slug][] = $this->endpointPatternToRegex($endpoint);
                }
            }
        }

        return $this->endpointPatterns;
    }

    /**
     * Convert a host pattern with {placeholder} to a regex.
     *
     * Placeholders match any non-dot characters: [^.]+
     * For example: '{resource}.openai.azure.com' => '/^[^.]+\.openai\.azure\.com$/'
     */
    protected function hostPatternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '/');
        $regex = preg_replace('/\\\{[^}]+\\\}/', '[^.]+', $escaped);

        return '/^'.$regex.'$/';
    }

    /**
     * Convert an endpoint pattern with {placeholder} to a regex.
     *
     * Placeholders match any non-slash characters: [^/]+
     * For example: '/v1beta/models/{model}:generateContent'
     *           => '/^\/v1beta\/models\/[^\/]+:generateContent$/'
     */
    protected function endpointPatternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $regex = preg_replace('/\\\{[^}]+\\\}/', '[^/]+', $escaped);

        return '#^'.$regex.'$#';
    }
}
