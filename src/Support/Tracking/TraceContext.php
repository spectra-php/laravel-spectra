<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * The trace that groups every AI request of one unit of work: an HTTP
 * request, a queued job, or an Artisan command.
 *
 * The trace id is kept in Laravel's request-scoped Context (as hidden data,
 * so it never appears in logs). That gives three properties for free:
 *
 * - every AI call in the same request/job/command gets the same trace id;
 * - the next request or job starts a new trace (Context is reset between them);
 * - queued jobs dispatched during a request carry the trace on (Laravel copies
 *   hidden Context into the job payload), so request → job → AI call is one chain.
 *
 * When the incoming HTTP request carries a W3C `traceparent` header, its trace
 * id (and parent span id) is used, so Spectra's spans join the caller's
 * distributed trace.
 *
 * Inherited tags travel the same way: queued jobs get the tags of the work
 * that dispatched them, and another of your services gets the trace and tags
 * from a `baggage` header ({@see toBaggage()}, read by the `spectra.trace`
 * middleware).
 */
class TraceContext
{
    public const TRACE_ID = 'spectra.trace_id';

    public const PARENT_SPAN_ID = 'spectra.parent_span_id';

    /** Tags handed down by the work that started this one (a request, a job). */
    public const TAGS = 'spectra.tags';

    /** The W3C `baggage` members Spectra reads and writes. */
    public const BAGGAGE_TRACE_ID = 'spectra.trace_id';

    public const BAGGAGE_TAGS = 'spectra.tags';

    /** At most this many inherited tags, each at most this long (the tag column). */
    protected const MAX_TAGS = 32;

    protected const MAX_TAG_LENGTH = 255;

    /**
     * The trace id for the current unit of work, created on first use.
     */
    public static function traceId(): string
    {
        try {
            $existing = Context::getHidden(self::TRACE_ID);

            if (is_string($existing) && $existing !== '') {
                return $existing;
            }

            [$traceId, $parentSpanId] = self::fromTraceparent() ?? [(string) Str::uuid(), null];

            Context::addHidden(self::TRACE_ID, $traceId);

            if ($parentSpanId !== null) {
                Context::addHidden(self::PARENT_SPAN_ID, $parentSpanId);
            }

            return $traceId;
        } catch (Throwable) {
            // Context unavailable (e.g. a bare container): fall back to one id per call.
            return (string) Str::uuid();
        }
    }

    /**
     * The span id of the incoming request's span, from `traceparent`.
     */
    public static function parentSpanId(): ?string
    {
        try {
            $parent = Context::getHidden(self::PARENT_SPAN_ID);

            return is_string($parent) && $parent !== '' ? $parent : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Use the given trace id for the rest of this unit of work (and the jobs
     * it dispatches).
     */
    public static function set(string $traceId): void
    {
        try {
            Context::addHidden(self::TRACE_ID, $traceId);
        } catch (Throwable) {
            // Nothing to propagate to; the id still applies via Spectra's globals.
        }
    }

    /**
     * The tags this unit of work inherited: from the request or job that
     * dispatched it, or from an upstream service ({@see fromBaggage()}).
     *
     * @return array<int, string>
     */
    public static function tags(): array
    {
        try {
            $tags = Context::getHidden(self::TAGS);
        } catch (Throwable) {
            return [];
        }

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }

    /**
     * Inherit these tags for the rest of this unit of work (and the jobs it
     * dispatches).
     *
     * @param  array<int, string>  $tags
     */
    public static function addTags(array $tags): void
    {
        try {
            Context::addHidden(self::TAGS, self::sanitizeTags([...self::tags(), ...$tags]));
        } catch (Throwable) {
            //
        }
    }

    public static function forgetTags(): void
    {
        try {
            Context::forgetHidden(self::TAGS);
        } catch (Throwable) {
            //
        }
    }

    /**
     * A W3C `baggage` header carrying the trace id and tags to another of
     * your services, which continues them with the `spectra.trace` middleware.
     *
     * @param  array<int, string>  $tags
     */
    public static function toBaggage(?string $traceId, array $tags): ?string
    {
        $members = [];

        if ($traceId !== null && $traceId !== '') {
            $members[] = self::BAGGAGE_TRACE_ID.'='.rawurlencode($traceId);
        }

        if (($tags = self::sanitizeTags($tags)) !== []) {
            $members[] = self::BAGGAGE_TAGS.'='.rawurlencode(implode(',', $tags));
        }

        return $members === [] ? null : implode(',', $members);
    }

    /**
     * The trace id and tags of a `baggage` header ({@see toBaggage()}). Other
     * members are left alone, and a trace id that is not a UUID is dropped.
     *
     * @return array{traceId: string|null, tags: array<int, string>}
     */
    public static function fromBaggage(?string $header): array
    {
        $traceId = null;
        $tags = [];

        foreach (explode(',', (string) $header) as $member) {
            // `key=value;property...`: the properties are not Spectra's.
            [$pair] = explode(';', $member, 2);

            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            $value = rawurldecode($value);

            if ($key === self::BAGGAGE_TRACE_ID && Str::isUuid($value)) {
                $traceId = strtolower($value);
            } elseif ($key === self::BAGGAGE_TAGS) {
                $tags = self::sanitizeTags(explode(',', $value));
            }
        }

        return ['traceId' => $traceId, 'tags' => $tags];
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    protected static function sanitizeTags(array $tags): array
    {
        $tags = array_map(fn (mixed $tag): string => is_string($tag) ? trim($tag) : '', $tags);
        $tags = array_filter($tags, fn (string $tag): bool => $tag !== '' && mb_strlen($tag) <= self::MAX_TAG_LENGTH);

        return array_slice(array_values(array_unique($tags)), 0, self::MAX_TAGS);
    }

    /**
     * Forget the current trace, so the next AI call starts a new one.
     */
    public static function reset(): void
    {
        try {
            Context::forgetHidden([self::TRACE_ID, self::PARENT_SPAN_ID]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Parse a W3C `traceparent` header (`00-{trace-id}-{parent-id}-{flags}`)
     * from the current HTTP request. The 32-hex trace id is stored as a UUID,
     * which is the same 128 bits and exports back to the same OTLP trace id.
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function fromTraceparent(): ?array
    {
        // Only an HTTP request carries the header; the console's stand-in
        // request has none, so commands and queued jobs fall through.
        if (! config('spectra.tracking.use_traceparent', true) || ! app()->bound('request')) {
            return null;
        }

        $header = request()->header('traceparent');

        if (! is_string($header)
            || ! preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($header)), $matches)
            || trim($matches[1], '0') === ''
            || trim($matches[2], '0') === '') {
            return null;
        }

        $hex = $matches[1];
        $uuid = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

        return [$uuid, $matches[2]];
    }
}
