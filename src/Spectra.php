<?php

namespace Spectra;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\ResponseProcessor;
use Spectra\Support\Tracking\StreamingTracker;
use Throwable;

class Spectra
{
    use Macroable;

    protected Application $app;

    protected ?RequestContext $currentContext = null;

    /** @var array<int, string> */
    protected array $globalTags = [];

    protected ?string $globalTrackableType = null;

    protected string|int|null $globalTrackableId = null;

    protected ?string $globalPricingTier = null;

    protected ?string $globalTraceId = null;

    /** @var array<string, mixed> */
    protected array $globalMetadata = [];

    protected ?RequestContext $pendingStreamContext = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function isEnabled(): bool
    {
        return (bool) config('spectra.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function startRequest(string $provider, string $model, array $options = []): RequestContext
    {
        $context = new RequestContext([
            'provider' => $provider,
            'model' => $model,
            'tags' => array_merge($this->globalTags, $options['tags'] ?? []),
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
            'trackable_type' => $options['trackable_type'] ?? $this->globalTrackableType,
            'trackable_id' => $options['trackable_id'] ?? $this->globalTrackableId,
            'trace_id' => $options['trace_id'] ?? $this->globalTraceId,
            ...$options,
        ]);

        if ($this->globalPricingTier && $context->pricingTier === null) {
            $context->withPricingTier($this->globalPricingTier);
        }

        $this->currentContext = $context;

        return $context;
    }

    /**
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function recordSuccess(RequestContext $context, mixed $response, TokenMetrics|array $usage = []): SpectraRequest
    {
        $result = app(ResponseProcessor::class)->processResponse($context, $response);

        if ($result !== null) {
            [$processedResponse, $processedUsage] = $result;
            $response = $processedResponse;

            if (empty($usage) && $processedUsage !== null) {
                $usage = $processedUsage;
            }
        }

        $context->complete($response, $usage);

        return $this->getPersister()->persist($context);
    }

    public function recordFailure(
        RequestContext $context,
        Throwable $exception,
        ?int $httpStatus = null
    ): SpectraRequest {
        $context->fail($exception, $httpStatus);

        return $this->getPersister()->persist($context);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function track(
        string $provider,
        string $model,
        callable $callback,
        array $options = []
    ): mixed {
        if (! $this->isEnabled()) {
            return $callback(new RequestContext(['provider' => $provider, 'model' => $model]));
        }

        $context = $this->startRequest($provider, $model, $options);

        try {
            $result = $callback($context);

            $this->recordSuccess($context, $this->toRawResponse($result));

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($context, $e);
            throw $e;
        }
    }

    /**
     * Create a streaming tracker for tracking streaming AI responses.
     *
     * Provider and model are optional - they will be auto-detected from the stream.
     *
     * Usage:
     *   $tracker = Spectra::stream();
     *   foreach ($tracker->track($stream) as $chunk) {
     *       echo $chunk;
     *   }
     *   $result = $tracker->finish();
     */
    /**
     * @param  array<string, mixed>  $options
     */
    public function stream(?string $provider = null, ?string $model = null, array $options = []): StreamingTracker
    {
        return new StreamingTracker($provider, $model, $options);
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function addGlobalTags(array $tags): self
    {
        $this->globalTags = array_merge($this->globalTags, $tags);

        return $this;
    }

    public function withPricingTier(string $tier): self
    {
        $this->globalPricingTier = $tier;

        return $this;
    }

    public function withTraceId(string $traceId): self
    {
        $this->globalTraceId = $traceId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->globalMetadata = array_merge($this->globalMetadata, $metadata);

        return $this;
    }

    public function clearGlobals(): self
    {
        $this->globalTags = [];
        $this->globalTrackableType = null;
        $this->globalTrackableId = null;
        $this->globalPricingTier = null;
        $this->globalTraceId = null;
        $this->globalMetadata = [];

        return $this;
    }

    public function forTrackable(Model $trackable): self
    {
        $this->globalTrackableType = get_class($trackable);
        $this->globalTrackableId = $trackable->getKey();

        return $this;
    }

    public function forUser(Model $user): self
    {
        return $this->forTrackable($user);
    }

    public function getCurrentContext(): ?RequestContext
    {
        return $this->currentContext;
    }

    public function setPendingStreamContext(RequestContext $context): void
    {
        $this->pendingStreamContext = $context;
    }

    public function consumePendingStreamContext(): ?RequestContext
    {
        $context = $this->pendingStreamContext;
        $this->pendingStreamContext = null;

        return $context;
    }

    public function usage(): UsageQueryBuilder
    {
        return new UsageQueryBuilder;
    }

    public function costs(): CostQueryBuilder
    {
        return new CostQueryBuilder;
    }

    public function getPersister(): RequestPersister
    {
        return $this->app->make(RequestPersister::class);
    }

    protected function toRawResponse(mixed $result): mixed
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        if (is_object($result)) {
            $json = json_encode($result);

            return $json !== false ? json_decode($json, true) : null;
        }

        return $result;
    }
}
