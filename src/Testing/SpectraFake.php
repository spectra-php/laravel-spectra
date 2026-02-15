<?php

namespace Spectra\Testing;

use PHPUnit\Framework\Assert;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\ResponseProcessor;

class SpectraFake extends Spectra
{
    /** @var array<int, array<string, mixed>> */
    protected array $recorded = [];

    /** @var array<string, mixed> */
    protected array $fakeResponses = [];

    protected bool $enabled = true;

    /**
     * @param  array<string, mixed>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->fakeResponses = $responses;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    public function enable(): self
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function startRequest(string $provider, string $model, array $options = []): RequestContext
    {
        return new RequestContext([
            'provider' => $provider,
            'model' => $model,
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
            ...$options,
        ]);
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

            $this->recordSuccess($context, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($context, $e);
            throw $e;
        }
    }

    /**
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

            if (empty($usage) && $processedUsage !== null) {
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
     * @return array<int, array<string, mixed>>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSuccessful(): array
    {
        return array_filter($this->recorded, fn ($r) => $r['type'] === 'success');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFailed(): array
    {
        return array_filter($this->recorded, fn ($r) => $r['type'] === 'failure');
    }

    public function assertRequestCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recorded,
            "Expected {$count} AI requests to be tracked, but got ".count($this->recorded)
        );
    }

    public function assertTracked(callable $callback): void
    {
        $matching = collect($this->recorded)->filter($callback);

        Assert::assertTrue(
            $matching->isNotEmpty(),
            'No matching AI request was tracked.'
        );
    }

    public function assertProviderUsed(string $provider): void
    {
        $this->assertTracked(fn ($r) => $r['context']->provider === $provider);
    }

    public function assertModelUsed(string $model): void
    {
        $this->assertTracked(fn ($r) => $r['context']->model === $model);
    }

    /**
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

    public function assertNothingTracked(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no AI requests to be tracked, but '.count($this->recorded).' were tracked.'
        );
    }

    public function assertSuccessful(): void
    {
        Assert::assertNotEmpty(
            $this->getSuccessful(),
            'Expected at least one successful AI request, but none were tracked.'
        );
    }

    public function assertFailed(): void
    {
        Assert::assertNotEmpty(
            $this->getFailed(),
            'Expected at least one failed AI request, but none were tracked.'
        );
    }

    public function assertTotalTokens(int $expectedTokens): void
    {
        $actualTokens = collect($this->recorded)
            ->sum(fn ($r) => $r['context']->totalTokens);

        Assert::assertEquals(
            $expectedTokens,
            $actualTokens,
            "Expected {$expectedTokens} total tokens, but got {$actualTokens}."
        );
    }

    public function reset(): self
    {
        $this->recorded = [];

        return $this;
    }
}
