<?php

namespace Spectra\Providers;

use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

abstract class Provider
{
    /** @var Handler[]|null */
    private ?array $cachedHandlers = null;

    abstract public function getProvider(): string;

    /**
     * @return array<string>
     */
    abstract public function getHosts(): array;

    /**
     * @return Handler[]
     */
    abstract public function handlers(): array;

    /**
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

    /** @param  array<string, mixed>  $responseData */
    public function resolveHandler(string $endpoint, array $responseData = []): ?Handler
    {
        $matches = [];

        foreach ($this->resolvedHandlers() as $handler) {
            if ($handler->matchesEndpoint($endpoint)) {
                $matches[] = $handler;
            }
        }

        // No endpoint matches — try response shape matching (specialists last, checked first)
        if (empty($matches) && ! empty($responseData)) {
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
        if (! empty($responseData)) {
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
        if ($endpoint !== '') {
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

    /** @param  array<string, mixed>  $requestData */
    public function extractMetrics(mixed $response, string $endpoint = '', array $requestData = []): Metrics
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler === null) {
            return new Metrics;
        }

        return $handler->extractMetrics($requestData, $this->toArray($response));
    }

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

        if (empty($data)) {
            return null;
        }

        $encoded = json_encode($data);

        return is_string($encoded) ? $encoded : null;
    }

    public function extractModel(mixed $response, string $endpoint = ''): ?string
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler !== null) {
            return $handler->extractModel($this->toArray($response));
        }

        return null;
    }

    public function extractFinishReason(mixed $response, string $endpoint = ''): ?string
    {
        $handler = $this->resolveHandlerFor($endpoint, $response);

        if ($handler instanceof HasFinishReason) {
            return $handler->extractFinishReason($this->toArray($response));
        }

        return null;
    }

    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        return $this->toArray($response)['id'] ?? null;
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

        return $requestData['model'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    public function resolveModelType(string $endpoint, array $requestData = []): ?ModelType
    {
        return $this->resolveHandler($endpoint)?->modelType();
    }

    public function isStreamingResponse(mixed $response): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
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
