<?php

declare(strict_types=1);

namespace Spectra\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\StreamingTracker;
use Spectra\Testing\SpectraFake;

/**
 * Static access to the request-scoped Spectra instance: manual tracking,
 * tags, trackables, usage and cost queries, and `Spectra::fake()` in tests.
 *
 * @method static bool isEnabled()
 * @method static RequestContext startRequest(string $provider, string $model, array<string, mixed> $options = [])
 * @method static SpectraRequest recordSuccess(RequestContext $context, mixed $response, TokenMetrics|array<string, mixed> $usage = [])
 * @method static SpectraRequest recordFailure(RequestContext $context, \Throwable $exception, ?int $httpStatus = null)
 * @method static mixed track(string $provider, string $model, callable $callback, array<string, mixed> $options = [])
 * @method static mixed span(string $name, callable $callback, array<string, mixed> $attributes = [])
 * @method static StreamingTracker stream(?string $provider = null, ?string $model = null, array<string, mixed> $options = [])
 * @method static \Spectra\Spectra addGlobalTags(array<int, string> $tags)
 * @method static mixed withTags(array<int, string> $tags, callable $callback)
 * @method static array<int, string> currentTags()
 * @method static array<string, string> propagationHeaders()
 * @method static \Spectra\Spectra withPricingTier(string $tier)
 * @method static \Spectra\Spectra withTraceId(string $traceId)
 * @method static string|null currentTraceId()
 * @method static \Spectra\Spectra newTrace()
 * @method static \Spectra\Spectra withMetadata(array<string, mixed> $metadata)
 * @method static \Spectra\Spectra clearGlobals()
 * @method static \Spectra\Spectra forTrackable(Model $trackable)
 * @method static \Spectra\Spectra forUser(Model|int $user)
 * @method static RequestContext|null getCurrentContext()
 * @method static UsageQueryBuilder usage()
 * @method static CostQueryBuilder costs()
 * @method static RequestPersister getPersister()
 *
 * Testing helpers (available after calling Spectra::fake(), which swaps in a SpectraFake):
 * @method static void assertRequestCount(int $count)
 * @method static void assertTracked(callable $callback)
 * @method static void assertProviderUsed(string $provider)
 * @method static void assertModelUsed(string $model)
 * @method static void assertTrackedWithTags(array<int, string> $tags)
 * @method static void assertTrackedWithMetadata(array<string, mixed> $metadata)
 * @method static void assertNothingTracked()
 * @method static void assertSuccessful()
 * @method static void assertFailed()
 * @method static void assertTotalTokens(int $expectedTokens)
 * @method static void assertSpanRecorded(string $name, ?callable $callback = null)
 * @method static array<int, array<string, mixed>> getSpans()
 * @method static array<int, array<string, mixed>> getRecorded()
 * @method static array<int, array<string, mixed>> getSuccessful()
 * @method static array<int, array<string, mixed>> getFailed()
 * @method static SpectraFake disable()
 * @method static SpectraFake enable()
 * @method static SpectraFake reset()
 *
 * @see \Spectra\Spectra
 * @see SpectraFake
 */
class Spectra extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Spectra\Spectra::class;
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    public static function fake(array $responses = []): SpectraFake
    {
        static::swap($fake = new SpectraFake($responses));

        return $fake;
    }
}
