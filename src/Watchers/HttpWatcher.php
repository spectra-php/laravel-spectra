<?php

namespace Spectra\Watchers;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Spectra\Concerns\ParsesRequestData;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Contracts\Watcher;
use Spectra\Spectra;
use Spectra\Support\ProviderRegistry;

/**
 * Watcher for Laravel's HTTP client.
 *
 * Automatically intercepts requests to AI providers made through
 * Laravel's Http facade and tracks them.
 */
class HttpWatcher implements Watcher
{
    use ParsesRequestData;

    protected Spectra $manager;

    protected ?ProviderRegistry $registry = null;

    /**
     * In-flight request contexts keyed by request hash.
     *
     * @var array<string, \Spectra\Support\Tracking\RequestContext>
     */
    protected array $contexts = [];

    public function __construct(Spectra $manager)
    {
        $this->manager = $manager;
    }

    public static function isAvailable(): bool
    {
        return class_exists(RequestSending::class);
    }

    public function register(): void
    {
        Event::listen(RequestSending::class, [$this, 'handleRequestSending']);
        Event::listen(ResponseReceived::class, [$this, 'handleResponseReceived']);
        Event::listen(ConnectionFailed::class, [$this, 'handleConnectionFailed']);
    }

    public function handleRequestSending(RequestSending $event): void
    {
        if (! $this->manager->isEnabled()) {
            return;
        }

        $request = $event->request;

        if ($request->hasHeader('X-Spectra-Manual-Tracking')) {
            return;
        }

        $uri = $request->toPsrRequest()->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort();
        $path = $uri->getPath();

        // Include port for matching (e.g., localhost:11434)
        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        $provider = $this->getRegistry()->detectProvider($hostWithPort)
            ?? $this->getRegistry()->detectProvider($host);

        if (! $provider) {
            return;
        }

        if (! $this->shouldWatch($provider)) {
            return;
        }

        if (! $this->getRegistry()->isTrackableEndpoint($provider, $path)) {
            return;
        }

        $requestData = self::parseRequestData($request);

        $providerInstance = $this->getRegistry()->provider($provider);
        $model = $providerInstance?->extractModelFromRequest($requestData, $path) ?? 'unknown';

        $context = $this->manager->startRequest($provider, $model, [
            'endpoint' => $uri->getPath(),
            'operation' => $request->method(),
            'auto_tracked' => true,
        ]);

        $context->requestData = $requestData;
        $context->endpoint = $uri->getPath();

        if ($context->pricingTier === null) {
            $tier = $providerInstance instanceof ExtractsPricingTierFromRequest
                ? $providerInstance->extractPricingTierFromRequest($requestData)
                : null;
            $context->withPricingTier($tier ?? config("spectra.costs.provider_settings.{$provider}.default_tier", 'standard'));
        }

        $hash = $this->getRequestHash($request);
        $this->contexts[$hash] = $context;
    }

    public function handleResponseReceived(ResponseReceived $event): void
    {
        $hash = $this->getRequestHash($event->request);

        if (! isset($this->contexts[$hash])) {
            return;
        }

        $context = $this->contexts[$hash];
        unset($this->contexts[$hash]);

        $response = $event->response;
        $context->httpStatus = $response->status();

        if ($response->failed()) {
            $this->manager->recordFailure(
                $context,
                new \Exception($response->body()),
                $response->status()
            );

            return;
        }

        $handler = $this->getRegistry()->provider($context->provider)
            ?->resolveHandler($context->endpoint ?? '');

        if ($handler instanceof ReturnsBinaryResponse) {
            $context->rawResponseBody = $response->body();
            $body = [];
        } else {
            $body = $response->json() ?? [];
        }

        if ($this->shouldSkipResponse($context, $body)) {
            return;
        }

        $this->manager->recordSuccess($context, $body);
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $hash = $this->getRequestHash($event->request);

        if (! isset($this->contexts[$hash])) {
            return;
        }

        $context = $this->contexts[$hash];
        unset($this->contexts[$hash]);

        $this->manager->recordFailure(
            $context,
            $event->exception
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function shouldSkipResponse(
        \Spectra\Support\Tracking\RequestContext $context,
        array $body
    ): bool {
        $providerInstance = $this->getRegistry()->provider($context->provider);
        $handler = $providerInstance?->resolveHandler($context->endpoint ?? '', $body);

        return $handler instanceof SkipsResponse && $handler->shouldSkipResponse($body);
    }

    /**
     * Check if provider should be watched.
     *
     * A provider is watchable if it's registered in the providers config.
     */
    protected function shouldWatch(string $provider): bool
    {
        return in_array($provider, $this->getRegistry()->slugs());
    }

    /**
     * @param  \Illuminate\Http\Client\Request  $request
     */
    protected function getRequestHash($request): string
    {
        $psr = $request->toPsrRequest();

        return md5(
            $psr->getMethod().
            (string) $psr->getUri().
            spl_object_id($request)
        );
    }

    protected function getRegistry(): ProviderRegistry
    {
        if ($this->registry === null) {
            $this->registry = app(ProviderRegistry::class);
        }

        return $this->registry;
    }
}
