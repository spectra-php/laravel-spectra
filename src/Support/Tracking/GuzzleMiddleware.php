<?php

namespace Spectra\Support\Tracking;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Spectra;
use Spectra\Support\ProviderRegistry;

/**
 * Guzzle middleware for tracking AI requests.
 *
 * Usage:
 * ```php
 * use Spectra\Support\Tracking\GuzzleMiddleware;
 * use GuzzleHttp\Client;
 * use GuzzleHttp\HandlerStack;
 *
 * $stack = HandlerStack::create();
 * $stack->push(GuzzleMiddleware::create('openai', 'gpt-4o'));
 *
 * $client = new Client(['handler' => $stack]);
 * ```
 */
class GuzzleMiddleware
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected Spectra $manager,
        protected string $provider,
        protected string $defaultModel = 'unknown',
        protected array $options = [],
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function create(
        string $provider,
        string $defaultModel = 'unknown',
        array $options = []
    ): callable {
        return function (callable $handler) use ($provider, $defaultModel, $options) {
            $manager = app(Spectra::class);
            $middleware = new self($manager, $provider, $defaultModel, $options);

            return function (RequestInterface $request, array $requestOptions) use ($handler, $middleware) {
                return $middleware($handler, $request, $requestOptions);
            };
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        if (! $this->manager->isEnabled()) {
            return $handler($request, $options);
        }

        $registry = app(ProviderRegistry::class);
        $provider = $this->resolveProvider($request, $registry);

        if ($provider === null) {
            return $handler($request, $options);
        }

        $uri = $request->getUri();

        if (! $registry->isTrackableEndpoint($provider, $uri->getPath())) {
            return $handler($request, $options);
        }

        $requestData = $this->parseRequestBody($request);

        $providerInstance = $registry->provider($provider);
        $model = $providerInstance?->extractModelFromRequest($requestData, $uri->getPath())
            ?? $this->defaultModel;

        $context = $this->manager->startRequest($provider, $model, array_merge([
            'endpoint' => $request->getUri()->getPath(),
            'operation' => $request->getMethod(),
        ], $this->options));

        $context->requestData = $requestData;
        $context->endpoint = $request->getUri()->getPath();

        if ($context->pricingTier === null) {
            $tier = $providerInstance instanceof ExtractsPricingTierFromRequest
                ? $providerInstance->extractPricingTierFromRequest($requestData)
                : null;
            $context->withPricingTier(
                $tier
                ?? $this->options['pricing_tier']
                ?? config("spectra.costs.provider_settings.{$provider}.default_tier", 'standard')
            );
        }

        return $handler($request, $options)->then(
            function (ResponseInterface $response) use ($context, $requestData) {
                // Skip automatic tracking for streaming responses
                // User should use Spectra::stream() for these
                if ($this->isStreamingResponse($requestData, $response, $context->provider, $context->endpoint)) {
                    $this->manager->setPendingStreamContext($context);

                    return $response;
                }

                $this->handleSuccess($context, $response);

                return $response;
            },
            function (\Throwable $exception) use ($context) {
                $httpStatus = null;
                if (method_exists($exception, 'getResponse') && $exception->getResponse()) {
                    $httpStatus = $exception->getResponse()->getStatusCode();
                }

                $this->manager->recordFailure($context, $exception, $httpStatus);

                throw $exception;
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseRequestBody(RequestInterface $request): array
    {
        try {
            $contentType = $request->getHeaderLine('Content-Type');

            if (str_contains($contentType, 'multipart/form-data')) {
                return $this->parseMultipartBody($request, $contentType);
            }

            $body = (string) $request->getBody();

            try {
                $request->getBody()->rewind();
            } catch (\Throwable) {
                // Some streams don't support rewind — body is still usable for parsing
            }

            return json_decode($body, true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Parse multipart/form-data body to extract text fields (skipping file uploads).
     *
     * Only reads the first 8KB to avoid loading large audio/image files into memory.
     */
    /**
     * @return array<string, mixed>
     */
    protected function parseMultipartBody(RequestInterface $request, string $contentType): array
    {
        try {
            preg_match('/boundary=(.+?)(?:;|$)/', $contentType, $matches);
            $boundary = trim($matches[1] ?? '', '"');

            if ($boundary === '') {
                return [];
            }

            $body = $request->getBody()->read(8192);
            $request->getBody()->rewind();

            $fields = [];
            $parts = explode('--'.$boundary, $body);

            foreach ($parts as $part) {
                $part = ltrim($part, "\r\n");

                if ($part === '' || $part === '--' || str_starts_with($part, '--')) {
                    continue;
                }

                // Skip file uploads
                if (str_contains($part, 'filename=')) {
                    continue;
                }

                if (preg_match('/Content-Disposition:.*?name="([^"]+)"/i', $part, $nameMatch)) {
                    $headerEnd = strpos($part, "\r\n\r\n");

                    if ($headerEnd !== false) {
                        $value = substr($part, $headerEnd + 4);
                        $fields[$nameMatch[1]] = rtrim($value, "\r\n");
                    }
                }
            }

            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function handleSuccess(RequestContext $context, ResponseInterface $response): void
    {
        $context->httpStatus = $response->getStatusCode();

        try {
            $rawBody = (string) $response->getBody();
            $response->getBody()->rewind();

            $handler = app(ProviderRegistry::class)->provider($context->provider)
                ?->resolveHandler($context->endpoint ?? '');

            if ($handler instanceof ReturnsBinaryResponse) {
                $context->rawResponseBody = $rawBody;
                $body = [];
            } else {
                $body = json_decode($rawBody, true) ?? [];
            }

            if ($this->shouldSkipResponse($context, $body)) {
                return;
            }

            $this->manager->recordSuccess($context, $body);
        } catch (\Throwable $e) {
            $this->manager->recordFailure($context, $e, $response->getStatusCode());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function shouldSkipResponse(RequestContext $context, array $body): bool
    {
        $providerInstance = app(ProviderRegistry::class)->provider($context->provider);
        $handler = $providerInstance?->resolveHandler($context->endpoint ?? '', $body);

        return $handler instanceof SkipsResponse && $handler->shouldSkipResponse($body);
    }

    protected function resolveProvider(RequestInterface $request, ProviderRegistry $registry): ?string
    {
        if ($this->provider !== 'auto') {
            return $this->provider;
        }

        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();

        if ($host === '') {
            return null;
        }

        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        return $registry->detectProvider($hostWithPort)
            ?? $registry->detectProvider($host);
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function isStreamingResponse(array $requestData, ResponseInterface $response, string $provider, ?string $endpoint): bool
    {
        if ($endpoint !== null) {
            $handler = app(ProviderRegistry::class)->provider($provider)?->resolveHandler($endpoint);
            if ($handler instanceof ReturnsBinaryResponse) {
                return false;
            }
        }

        // Check if the request explicitly asked for streaming
        if (! empty($requestData['stream'])) {
            return true;
        }

        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'text/event-stream')) {
            return true;
        }

        // Chunked without content-length and not JSON indicates streaming
        $transferEncoding = $response->getHeaderLine('Transfer-Encoding');
        if (str_contains($transferEncoding, 'chunked') && ! $response->hasHeader('Content-Length')) {
            if (! str_contains($contentType, 'application/json')) {
                return true;
            }
        }

        return false;
    }
}
