<?php

declare(strict_types=1);

namespace Spectra\Watchers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\ServiceProvider;
use Spectra\Contracts\Watcher;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\Tracking\GuzzleMiddleware;

/**
 * Watcher for the openai-php/laravel package.
 *
 * This watcher automatically intercepts all requests made through
 * the OpenAI facade by replacing the client binding with a tracked version.
 */
class OpenAiWatcher implements Watcher
{
    private const OPENAI_CONTRACT = ClientContract::class;

    private const OPENAI_CLIENT = \OpenAI\Client::class;

    private const OPENAI_SERVICE_PROVIDER = ServiceProvider::class;

    private const OPENAI_FACADE = \OpenAI\Laravel\Facades\OpenAI::class;

    /**
     * Determine if the OpenAI Laravel package and client are installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::OPENAI_SERVICE_PROVIDER)
            && class_exists(self::OPENAI_CLIENT);
    }

    /**
     * Swap in a tracked OpenAI client when the package is installed.
     */
    public function register(): void
    {
        if (! static::isAvailable()) {
            return;
        }

        $this->replaceOpenAiClient();
    }

    /**
     * Rebind the OpenAI client to one whose Guzzle stack runs the tracking middleware.
     */
    protected function replaceOpenAiClient(): void
    {
        $resolver = app(ApiKeyResolver::class);
        $apiKey = $resolver->resolve('openai');

        if (blank($apiKey)) {
            return;
        }

        $app = app();

        // Force the deferred OpenAI service provider to register first,
        // so our instance() calls below aren't overwritten when the
        // provider lazily registers its singleton on first resolve.
        if ($app->getProvider(self::OPENAI_SERVICE_PROVIDER) === null) {
            $app->register(self::OPENAI_SERVICE_PROVIDER);
        }

        $stack = HandlerStack::create();

        $stack->push(GuzzleMiddleware::create('openai'));

        $httpClient = new Client([
            'handler' => $stack,
            'timeout' => config('openai.request_timeout', 30),
        ]);

        $organization = config('openai.organization');
        $project = config('openai.project');
        $baseUri = $resolver->resolveBaseUrl('openai');

        $factory = \OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($httpClient);

        if ($organization) {
            $factory = $factory->withOrganization($organization);
        }

        if (is_string($project)) {
            $factory = $factory->withProject($project);
        }

        if (is_string($baseUri)) {
            $factory = $factory->withBaseUri($baseUri);
        }

        $trackedClient = $factory->make();

        // Replace all container bindings so facades and DI resolve to the tracked client
        $app->instance(self::OPENAI_CONTRACT, $trackedClient);
        $app->instance(self::OPENAI_CLIENT, $trackedClient);
        $app->instance('openai', $trackedClient);

        if (class_exists(self::OPENAI_FACADE)) {
            $facade = self::OPENAI_FACADE;
            $facade::clearResolvedInstances();
        }
    }
}
