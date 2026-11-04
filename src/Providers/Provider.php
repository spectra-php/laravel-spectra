<?php

declare(strict_types=1);

namespace Spectra\Providers;

use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * Base class for an AI provider Spectra can track: built-in ones and custom
 * providers users register.
 *
 * A provider names its slug, its hosts and its handlers, plus the quirks that
 * apply across its endpoints (where the response id lives, the pricing tier,
 * an alternate path layout). Everything about one endpoint belongs to a
 * handler, so this class mostly resolves the right handler and delegates.
 */
abstract class Provider
{
    /** @var Handler[]|null */
    private ?array $cachedHandlers = null;

    /**
     * Get the provider's slug, as stored on tracked requests.
     */
    abstract public function getProvider(): string;

    /**
     * Get the hosts this provider's API is served from.
     *
     * @return array<string>
     */
    abstract public function getHosts(): array;

    /**
     * Handlers for this provider's endpoints, generic handlers first.
     *
     * When several handlers match the same endpoint, resolution walks this
     * list in REVERSE and picks the first whose matchesResponse() accepts the
     * body — so specialists (narrow response shapes) must come after the
     * generic handler they specialize.
     *
     * @return Handler[]
     */
    abstract public function handlers(): array;

    /**
     * Rewrite a request path into the form this provider's endpoints use.
     *
     * Lets a provider be served under another path layout (Azure OpenAI's
     * /openai/deployments/{deployment}/... for example). Called before
     * endpoint matching; the path is returned unchanged by default.
     */
    public function normalizeEndpoint(string $path): string
    {
        return $path;
    }

    /**
     * Get the provider's handlers, built once per instance.
     *
     * @return Handler[]
     */
    protected function resolvedHandlers(): array
    {
        return $this->cachedHandlers ??= $this->handlers();
    }

    /**
     * Aggregate endpoints from all handlers.
     *
     * @return array<string>
     */
    public function getEndpoints(): array
    {
        $endpoints = [];

        foreach ($this->resolvedHandlers() as $handler) {
            $endpoints = array_merge($endpoints, $handler->endpoints());
        }

        return $endpoints;
    }

    /**
     * Resolve the handler for an endpoint, using the response shape when several or none match.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function resolveHandler(string $endpoint, array $responseData = []): ?Handler
    {
        $matches = [];

        foreach ($this->resolvedHandlers() as $handler) {
            if ($handler->matchesEndpoint($endpoint)) {
                $matches[] = $handler;
            }
        }

        // No endpoint matches — try response shape matching (specialists last, checked first)
        if (blank($matches) && filled($responseData)) {
            foreach (array_reverse($this->resolvedHandlers()) as $handler) {
                if ($handler instanceof MatchesResponseShape && $handler->matchesResponse($responseData)) {
                    return $handler;
                }
            }

            return null;
        }

        if (count($matches) <= 1) {
            return $matches[0] ?? null;
        }

        // Multiple handlers match the same endpoint — check specialists (later entries) first.
        if (filled($responseData)) {
            foreach (array_reverse($matches) as $handler) {
                if ($handler instanceof MatchesResponseShape && $handler->matchesResponse($responseData)) {
                    return $handler;
                }
            }
        }

        // No specialist matched — fall back to first (default) handler.
        return $matches[0];
    }

    /**
     * Resolve handler from endpoint, or infer from response shape via matchesResponse().
     */
    protected function resolveHandlerFor(string $endpoint, mixed $response): ?Handler
    {
        if (filled($endpoint)) {
            return $this->resolveHandler($endpoint, $this->toArray($response));
        }

        $data = $this->toArray($response);

        // Check specialists (later entries) first
        foreach (array_reverse($this->resolvedHandlers()) as $handler) {
            if ($handler instanceof MatchesResponseShape && $handler->matchesResponse($data)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Get the usage metrics of a response from the handler that owns it.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractMetrics(mixed $response, string $endpoint = '', array $requestData = []): Metrics
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler === null) {
            return new Metrics;
        }

        return $handler->extractMetrics($requestData, $this->toArray($response));
    }

    /**
     * Get the response text from its handler, or the JSON-encoded body as a fallback.
     */
    public function extractResponse(mixed $response, string $endpoint = ''): ?string
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler !== null) {
            $result = $handler->extractResponse($this->toArray($response));

            if ($result !== null) {
                return $result;
            }
        }

        $data = $this->toArray($response);

        if (blank($data)) {
            return null;
        }

        $encoded = json_encode($data);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * Get the model named in the response, when its handler reads one.
     */
    public function extractModel(mixed $response, string $endpoint = ''): ?string
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler instanceof ExtractsModelFromResponse) {
            return $handler->extractModelFromResponse($this->toArray($response));
        }

        return null;
    }

    /**
     * Get the response's finish reason, when its handler reads one.
     */
    public function extractFinishReason(mixed $response, string $endpoint = ''): ?string
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler instanceof HasFinishReason) {
            return $handler->extractFinishReason($this->toArray($response));
        }

        return null;
    }

    /**
     * Get the response id from the body's `id` key.
     */
    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $id = $this->toArray($response)['id'] ?? null;

        return filled($id) ? $id : null;
    }

    /**
     * Extract the model name from request data and endpoint.
     *
     * Delegates to the handler if it implements ExtractsModelFromRequest,
     * otherwise falls back to $requestData['model'].
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $handler = $this->resolveHandler($endpoint);

        if ($handler instanceof ExtractsModelFromRequest) {
            return $handler->extractModelFromRequest($requestData, $endpoint);
        }

        return filled($requestData['model'] ?? null) ? $requestData['model'] : null;
    }

    /**
     * Resolve the model type of the handler that owns the endpoint.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function resolveModelType(string $endpoint, array $requestData = []): ?ModelType
    {
        return $this->resolveHandler($endpoint)?->modelType();
    }

    /**
     * Convert a response (array, SDK object or other object) to an array.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response)) {
            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            $encoded = json_encode($response);
            if (! is_string($encoded)) {
                return [];
            }

            $decoded = json_decode($encoded, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
