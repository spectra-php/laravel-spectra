<?php

declare(strict_types=1);

namespace Spectra\Testing;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\ResponseProcessor;

/**
 * Stand-in for Spectra swapped in by `Spectra::fake()`.
 *
 * Records tracked requests in memory instead of the database, so tests of
 * the user's own code can assert what was tracked without migrations or
 * real provider calls.
 */
class SpectraFake extends Spectra
{
    /** @var array<int, array<string, mixed>> */
    protected array $recorded = [];

    /** @var array<string, mixed> */
    protected array $fakeResponses = [];

    /**
     * Spans opened with span(), in the order they ended.
     *
     * @var array<int, array{name: string, attributes: array<string, mixed>, status: string, exception: ?\Throwable}>
     */
    protected array $spans = [];

    protected bool $enabled = true;

    /**
     * Create a new Spectra fake instance.
     *
     * @param  array<string, mixed>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->fakeResponses = $responses;
    }

    /**
     * Determine if the fake is recording requests.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Stop recording requests.
     */
    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Start recording requests again.
     */
    public function enable(): self
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * Start a request context with the global tags and metadata applied.
     *
     * @param  array<string, mixed>  $options
     */
    public function startRequest(string $provider, string $model, array $options = []): RequestContext
    {
        return new RequestContext([
            'provider' => $provider,
            'model' => $model,
            ...$options,
            'tags' => $this->mergedTags($options['tags'] ?? []),
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
        ]);
    }

    /**
     * Run the callback as a tracked AI request and record it in memory.
     *
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

            $this->recordSuccess($context, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($context, $e);
            throw $e;
        }
    }

    /**
     * Run the callback and remember the span instead of storing it.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @param  array<string, mixed>  $attributes
     * @return TResult
     */
    public function span(string $name, callable $callback, array $attributes = []): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->spans[] = ['name' => $name, 'attributes' => $attributes, 'status' => 'error', 'exception' => $e];

            throw $e;
        }

        $this->spans[] = ['name' => $name, 'attributes' => $attributes, 'status' => 'ok', 'exception' => null];

        return $result;
    }

    /**
     * Get the spans recorded with span(), in the order they ended.
     *
     * @return array<int, array{name: string, attributes: array<string, mixed>, status: string, exception: ?\Throwable}>
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Assert a span with this name was recorded, and that the callback (given
     * the recorded span) accepts one of them when given.
     */
    public function assertSpanRecorded(string $name, ?callable $callback = null): void
    {
        $matching = Arr::where(
            $this->spans,
            fn (array $span) => $span['name'] === $name && ($callback === null || $callback($span)),
        );

        Assert::assertNotEmpty($matching, "Expected a span named [{$name}] to be recorded, but none matched.");
    }

    /**
     * Complete the context with the processed response and usage, and record it in memory.
     *
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function recordSuccess(
        RequestContext $context,
        mixed $response,
        TokenMetrics|array $usage = []
    ): SpectraRequest {
        $result = app(ResponseProcessor::class)->processResponse($context, $response);

        if ($result !== null) {
            [$processedResponse, $processedUsage] = $result;
            $response = $processedResponse;

            if (blank($usage) && $processedUsage !== null) {
                $usage = $processedUsage;
            }
        }

        $context->complete($response, $usage);

        $this->recorded[] = [
            'type' => 'success',
            'context' => $context,
            'response' => $response,
            'usage' => $usage,
        ];

        return new SpectraRequest($context->toArray());
    }

    /**
     * Mark the context as failed with the exception and record it in memory.
     */
    public function recordFailure(
        RequestContext $context,
        \Throwable $exception,
        ?int $httpStatus = null
    ): SpectraRequest {
        $context->fail($exception, $httpStatus);

        $this->recorded[] = [
            'type' => 'failure',
            'context' => $context,
            'exception' => $exception,
            'http_status' => $httpStatus,
        ];

        return new SpectraRequest($context->toArray());
    }

    /**
     * Get every recorded request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * Get the recorded requests that succeeded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSuccessful(): array
    {
        return Arr::where($this->recorded, fn ($r) => $r['type'] === 'success');
    }

    /**
     * Get the recorded requests that failed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFailed(): array
    {
        return Arr::where($this->recorded, fn ($r) => $r['type'] === 'failure');
    }

    /**
     * Assert the given number of requests was tracked.
     */
    public function assertRequestCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recorded,
            "Expected {$count} AI requests to be tracked, but got ".count($this->recorded)
        );
    }

    /**
     * Assert a tracked request passes the given callback.
     */
    public function assertTracked(callable $callback): void
    {
        $matching = collect($this->recorded)->filter($callback);

        Assert::assertTrue(
            $matching->isNotEmpty(),
            'No matching AI request was tracked.'
        );
    }

    /**
     * Assert a request to the given provider was tracked.
     */
    public function assertProviderUsed(string $provider): void
    {
        $this->assertTracked(fn ($r) => $r['context']->provider === $provider);
    }

    /**
     * Assert a request to the given model was tracked.
     */
    public function assertModelUsed(string $model): void
    {
        $this->assertTracked(fn ($r) => $r['context']->model === $model);
    }

    /**
     * Assert a request was tracked with the given tags at the same positions.
     *
     * @param  array<int, string>  $tags
     */
    public function assertTrackedWithTags(array $tags): void
    {
        $this->assertTracked(function ($r) use ($tags) {
            foreach ($tags as $key => $value) {
                if (! isset($r['context']->tags[$key]) || $r['context']->tags[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Assert a request was tracked with the given metadata values.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function assertTrackedWithMetadata(array $metadata): void
    {
        $this->assertTracked(function ($r) use ($metadata) {
            foreach ($metadata as $key => $value) {
                if (! array_key_exists($key, $r['context']->metadata) || $r['context']->metadata[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Assert no request was tracked.
     */
    public function assertNothingTracked(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no AI requests to be tracked, but '.count($this->recorded).' were tracked.'
        );
    }

    /**
     * Assert at least one successful request was tracked.
     */
    public function assertSuccessful(): void
    {
        Assert::assertNotEmpty(
            $this->getSuccessful(),
            'Expected at least one successful AI request, but none were tracked.'
        );
    }

    /**
     * Assert at least one failed request was tracked.
     */
    public function assertFailed(): void
    {
        Assert::assertNotEmpty(
            $this->getFailed(),
            'Expected at least one failed AI request, but none were tracked.'
        );
    }

    /**
     * Assert the tracked requests used the given number of prompt and completion tokens.
     */
    public function assertTotalTokens(int $expectedTokens): void
    {
        $actualTokens = collect($this->recorded)
            ->sum(fn ($r) => $r['context']->promptTokens + $r['context']->completionTokens);

        Assert::assertEquals(
            $expectedTokens,
            $actualTokens,
            "Expected {$expectedTokens} total tokens, but got {$actualTokens}."
        );
    }

    /**
     * Forget the recorded requests and spans.
     */
    public function reset(): self
    {
        $this->recorded = [];
        $this->spans = [];

        return $this;
    }
}
