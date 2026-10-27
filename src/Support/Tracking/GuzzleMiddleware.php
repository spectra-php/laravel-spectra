<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spectra\Concerns\ParsesRequestData;
use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Providers\Provider;
use Spectra\Spectra;
use Spectra\Support\ProviderRegistry;
use Throwable;

/**
 * Guzzle middleware that tracks AI requests.
 *
 * This is the one tracking path for every HTTP route into a provider:
 * Laravel's HTTP client (registered as global middleware by HttpWatcher),
 * the openai-php client (OpenAiWatcher), the tracked handler stacks
 * (GuzzleWatcher), and the `withAITracking()` macro.
 *
 * Rules it keeps:
 * - It never reads a body the application hasn't read yet unless the body
 *   can be rewound. Streamed and one-pass bodies are wrapped in a
 *   TrackedStream and recorded once the application has read them.
 * - Tracking errors are reported, never thrown into the application.
 *
 * Usage with your own Guzzle client:
 * ```php
 * $stack = HandlerStack::create();
 * $stack->push(GuzzleMiddleware::create('openai'));
 * $client = new Client(['handler' => $stack]);
 * ```
 */
class GuzzleMiddleware
{
    use ParsesRequestData;

    /** Request option that turns tracking off for one request (`Http::withoutAITracking()`). */
    public const OPTION_DISABLED = 'spectra_disabled';

    /** Request option set by `withAITracking()` so the global middleware leaves the request to it. */
    public const OPTION_MANUAL = 'spectra_manual';

    /** Legacy opt-out header, still honoured. */
    public const MANUAL_TRACKING_HEADER = 'X-Spectra-Manual-Tracking';

    /**
     * @param  string  $provider  A provider slug, or 'auto' to detect it from the host.
     * @param  array<string, mixed>  $options  Passed to Spectra::startRequest() (tags, metadata, pricing_tier, ...).
     * @param  bool  $manual  True for the `withAITracking()` macro: tracks any endpoint of the given provider.
     */
    public function __construct(
        protected string $provider,
        protected string $defaultModel = 'unknown',
        protected array $options = [],
        protected bool $manual = false,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function create(
        string $provider,
        string $defaultModel = 'unknown',
        array $options = [],
        bool $manual = false,
    ): callable {
        $middleware = new self($provider, $defaultModel, $options, $manual);

        return fn (callable $handler) => fn (RequestInterface $request, array $requestOptions) => $middleware($handler, $request, $requestOptions);
    }

    /**
     * Middleware that detects the provider from the request host.
     */
    public static function auto(): callable
    {
        return self::create('auto');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        $context = $this->safely(fn () => $this->start($request, $options));

        if (! $context instanceof RequestContext) {
            return $handler($request, $options);
        }

        try {
            $promise = $handler($request, $options);
        } catch (Throwable $exception) {
            // Some handlers (and HTTP fakes) throw instead of rejecting.
            $this->safely(fn () => $this->onException($context, $exception));

            throw $exception;
        }

        return $promise->then(
            fn (ResponseInterface $response) => $this->safely(fn () => $this->onResponse($context, $response)) ?? $response,
            function (Throwable $exception) use ($context) {
                $this->safely(fn () => $this->onException($context, $exception));

                throw $exception;
            }
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function start(RequestInterface $request, array $options): ?RequestContext
    {
        $manager = app(Spectra::class);

        if (! $manager->isEnabled() || ($options[self::OPTION_DISABLED] ?? false)) {
            return null;
        }

        // Requests handed to withAITracking() are tracked by its own middleware.
        if (! $this->manual && (($options[self::OPTION_MANUAL] ?? false) || $request->hasHeader(self::MANUAL_TRACKING_HEADER))) {
            return null;
        }

        $registry = app(ProviderRegistry::class);
        $provider = $this->resolveProvider($request, $registry);

        if ($provider === null) {
            return null;
        }

        $providerInstance = $registry->provider($provider);
        $path = $request->getUri()->getPath();
        $path = $providerInstance?->normalizeEndpoint($path) ?? $path;

        if (! $this->manual && ! $registry->isTrackableEndpoint($provider, $path)) {
            return null;
        }

        $requestData = self::parsePsrRequestData($request);

        $model = $providerInstance?->extractModelFromRequest($requestData, $path)
            ?? (is_string($requestData['model'] ?? null) ? $requestData['model'] : null)
            ?? $this->defaultModel;

        $context = $manager->startRequest($provider, $model, array_merge([
            'endpoint' => $path,
            'operation' => $request->getMethod(),
        ], $this->options));

        $context->requestData = $requestData;
        $context->endpoint = $path;

        if ($providerInstance instanceof ExtractsPricingTierFromRequest) {
            $context->requestedPricingTier = $providerInstance->extractPricingTierFromRequest($requestData);
        }

        return $context;
    }

    protected function onResponse(RequestContext $context, ResponseInterface $response): ResponseInterface
    {
        $context->httpStatus = $response->getStatusCode();

        if ($response->getStatusCode() >= 400) {
            return $this->withBody($response, fn (string $body) => $this->recordHttpError($context, $response->getStatusCode(), $body));
        }

        if (StreamDetector::isStreamingResponse($response, $context->provider, $context->endpoint, $context->requestData ?? [])) {
            return $this->trackStream($context, $response);
        }

        return $this->withBody($response, fn (string $body) => $this->recordBody($context, $body));
    }

    protected function onException(RequestContext $context, Throwable $exception): void
    {
        $httpStatus = null;

        if (method_exists($exception, 'getResponse') && ($response = $exception->getResponse()) instanceof ResponseInterface) {
            $httpStatus = $response->getStatusCode();
        }

        app(Spectra::class)->recordFailure($context, $exception, $httpStatus);
    }

    /**
     * Read the body now when it can be rewound; otherwise let the application
     * read it first and receive the bytes as it does.
     *
     * @param  callable(string): void  $record
     */
    protected function withBody(ResponseInterface $response, callable $record): ResponseInterface
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $contents = (string) $body;
            $body->rewind();
            $record($contents);

            return $response;
        }

        $buffer = '';

        return $response->withBody(new TrackedStream(
            $body,
            function (string $bytes) use (&$buffer) {
                $buffer .= $bytes;
            },
            function () use (&$buffer, $record) {
                $record($buffer);
            },
        ));
    }

    protected function trackStream(RequestContext $context, ResponseInterface $response): ResponseInterface
    {
        $manager = app(Spectra::class);
        $tracker = StreamingTracker::forContext($context);
        $parser = new StreamEventParser($response->getHeaderLine('Content-Type'));

        // A manual Spectra::stream() wrapped around the same response claims
        // this context and records it instead.
        $manager->setPendingStreamContext($context);

        return $response->withBody(new TrackedStream(
            $response->getBody(),
            function (string $bytes) use ($parser, $tracker, $context) {
                foreach ($parser->push($bytes) as $event) {
                    if (! $context->streamClaimed) {
                        $tracker->ingest($event);
                    }
                }
            },
            function () use ($parser, $tracker, $context) {
                foreach ($parser->finish() as $event) {
                    if (! $context->streamClaimed) {
                        $tracker->ingest($event);
                    }
                }

                app(Spectra::class)->clearPendingStreamContext($context);

                if (! $context->streamClaimed) {
                    $tracker->finish();
                }
            },
        ));
    }

    protected function recordBody(RequestContext $context, string $rawBody): void
    {
        $providerInstance = app(ProviderRegistry::class)->provider($context->provider);
        $handler = $providerInstance?->resolveHandler($context->endpoint ?? '');

        if ($handler instanceof ReturnsBinaryResponse) {
            $context->rawResponseBody = $rawBody;
            $body = [];
        } else {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $handler = $providerInstance?->resolveHandler($context->endpoint ?? '', $body);
        $jobId = $providerInstance?->extractResponseId($body, $context->endpoint ?? '');

        if ($handler instanceof SkipsResponse && $handler->shouldSkipResponse($body)) {
            // An async job was submitted or is still running; the poll that
            // sees it finish is recorded, and may not name the model or carry
            // the request body again.
            $this->rememberJob($context, $jobId);

            return;
        }

        $this->restoreJob($context, $providerInstance, $jobId);

        app(Spectra::class)->recordSuccess($context, $body);
    }

    /**
     * Remember the job's model and request body (its length, resolution and
     * other priced options) for the poll that records it. A later poll only
     * adds to what the submit left: it doesn't replace a full model with the
     * prefix a result URL names, or a body with an empty one.
     */
    protected function rememberJob(RequestContext $context, ?string $jobId): void
    {
        if ($jobId === null || $jobId === '') {
            return;
        }

        try {
            $job = $this->rememberedJob($context->provider, $jobId);
            $model = $context->model;

            if ($model !== '' && $model !== 'unknown' && ! str_starts_with($job['model'] ?? '', rtrim($model, '/').'/')) {
                $job['model'] = $model;
            }

            $request = $this->withoutInlineFiles($context->requestData ?? []);

            if ($request !== []) {
                $job['request'] = $request;
            }

            if ($job !== []) {
                Cache::put($this->jobModelKey($context->provider, $jobId), $job, now()->addDay());
            }
        } catch (Throwable) {
            // Without a cache the poll keeps whatever it can see.
        }
    }

    /**
     * Use the model remembered from the job's submit request when the poll
     * doesn't name one, or names only a prefix of it (fal's result URLs drop
     * the model's sub-path, e.g. `fal-ai/flux` for `fal-ai/flux/dev`), and
     * the submit's body under the poll's own (usually empty) one.
     */
    protected function restoreJob(RequestContext $context, ?Provider $providerInstance, ?string $jobId): void
    {
        if ($jobId === null || $jobId === '') {
            return;
        }

        try {
            $job = $this->rememberedJob($context->provider, $jobId);
        } catch (Throwable) {
            return;
        }

        $remembered = $job['model'] ?? null;

        if ($remembered !== null && $remembered !== $context->model
            && ($context->model === 'unknown' || $context->model === '' || str_starts_with($remembered, rtrim($context->model, '/').'/'))) {
            $context->model = $remembered;
        }

        if (isset($job['request'])) {
            $context->requestData = array_replace($job['request'], $context->requestData ?? []);

            if ($context->requestedPricingTier === null && $providerInstance instanceof ExtractsPricingTierFromRequest) {
                $context->requestedPricingTier = $providerInstance->extractPricingTierFromRequest($context->requestData);
            }
        }
    }

    /**
     * Earlier versions cached only the model, as a string.
     *
     * @return array{model?: string, request?: array<string, mixed>}
     */
    protected function rememberedJob(string $provider, string $jobId): array
    {
        $job = Cache::get($this->jobModelKey($provider, $jobId));

        if (is_string($job)) {
            return ['model' => $job];
        }

        return is_array($job) ? $job : [];
    }

    /**
     * The request body without inline files (data URIs), which can be
     * megabytes and say nothing about the price.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected function withoutInlineFiles(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->withoutInlineFiles($value);
            } elseif (is_string($value) && str_starts_with($value, 'data:')) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function jobModelKey(string $provider, string $jobId): string
    {
        return 'spectra:job-model:'.$provider.':'.sha1($jobId);
    }

    protected function recordHttpError(RequestContext $context, int $status, string $body): void
    {
        app(Spectra::class)->recordFailure($context, new ProviderErrorException(self::errorMessage($body, $status), $status), $status);
    }

    /**
     * The provider's error message from an error body, falling back to the raw body.
     */
    public static function errorMessage(string $body, int $status): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $error = $decoded['error'] ?? $decoded;
            $message = is_array($error)
                ? ($error['message'] ?? $error['detail'] ?? $decoded['message'] ?? $decoded['detail'] ?? null)
                : $error;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = trim($body);

        return $body !== '' ? mb_substr($body, 0, 2000) : "HTTP {$status}";
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
     * Run a tracking step; report and swallow anything it throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
