<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spectra\Enums\SpanKind;
use Spectra\Enums\SpanStatus;
use Throwable;

/**
 * The spans open right now, and the span every new AI call or span belongs to.
 *
 * Two kinds of span nest here. Spectra::span() blocks open and close around
 * the app's code. Units of work (the HTTP request, a queued job, the Artisan
 * command) become root spans: a unit's span id is made the first time a call
 * or span inside it needs a parent, and the span is saved when the unit ends,
 * so a unit that made no AI call stores nothing. The innermost open span is
 * the parent; with none open, it is the span the work was started from (the
 * incoming `traceparent`, or the span that dispatched the job).
 *
 * A queued job's parent may be a unit that made no AI call, so it is never
 * saved, and the job would hang off a span that doesn't exist. So a dispatch
 * hands the job that unit's span, and the unsaved spans the unit was handed
 * itself ({@see pendingForDispatch()}); the job saves them with its own span,
 * marked `spectra.inferred` and ended when the job was dispatched. The unit's
 * own span replaces that stand-in if the unit is saved after all.
 *
 * Bound as scoped: queue workers and Octane start each job and request with
 * an empty stack. A synchronous job pushes its unit on top of the request's.
 */
class SpanStack
{
    /** Keys of the HTTP request's and the Artisan command's units; jobs are keyed by job. */
    protected const HTTP_UNIT = 'http';

    protected const COMMAND_UNIT = 'command';

    /** The most unsaved spans a job carries: the nearest ones when a chain runs longer. */
    protected const MAX_PENDING_SPANS = 16;

    /** Marks a span a job saved for the unit that dispatched it. */
    public const INFERRED_ATTRIBUTE = 'spectra.inferred';

    /** The command the user ran, so a command it calls doesn't close its span. */
    protected ?string $commandName = null;

    /**
     * Open Spectra::span() blocks, innermost last.
     *
     * @var list<array{span_id: string, parent_span_id: ?string, trace_id: string, name: string, kind: SpanKind, origin: ?string, started_at: CarbonInterface, attributes: array<string, mixed>, opened: int}>
     */
    protected array $spans = [];

    /**
     * Open units of work, innermost last.
     *
     * @var array<string, array{kind: SpanKind, name: string, started_at: CarbonInterface, span_id: ?string, parent_span_id: ?string, trace_id: ?string, used: bool, opened: int, pending: list<array<string, mixed>>}>
     */
    protected array $units = [];

    /** Counts openings, so the span opened last (a span or a unit) is the current one. */
    protected int $opened = 0;

    /**
     * Create a new span stack instance.
     */
    public function __construct(
        protected SpanWriter $writer,
    ) {}

    /**
     * Start a unit of work (a request, job or command); it becomes a root
     * span if an AI call or span happens inside it.
     */
    public function startUnit(string $key, SpanKind $kind, string $name, ?CarbonInterface $startedAt = null): void
    {
        if (! config('spectra.tracking.root_spans', true)) {
            return;
        }

        $this->units[$key] = [
            'kind' => $kind,
            'name' => $name,
            'started_at' => $startedAt ?? Date::now(),
            'span_id' => null,
            'parent_span_id' => null,
            'trace_id' => null,
            'used' => false,
            'opened' => ++$this->opened,
            'pending' => [],
        ];
    }

    /**
     * Finish a unit of work (a request, job or command), saving its span if anything inside used it.
     */
    public function finishUnit(string $key, ?Throwable $error = null): void
    {
        $unit = $this->units[$key] ?? null;
        unset($this->units[$key]);

        if ($unit === null || ! $unit['used'] || $unit['span_id'] === null || $unit['trace_id'] === null) {
            return;
        }

        $this->writer->record($this->attributes(
            traceId: $unit['trace_id'],
            spanId: $unit['span_id'],
            parentSpanId: $unit['parent_span_id'],
            name: $unit['name'],
            kind: $unit['kind'],
            origin: $unit['name'],
            startedAt: $unit['started_at'],
            error: $error,
            attributes: [],
        ), deferrable: false);

        foreach ($unit['pending'] as $pending) {
            // A unit still open here (a synchronous job's request) saves its own span.
            if ($this->unitKeyOf($pending['span_id']) === null) {
                $this->writer->record([
                    ...$pending,
                    'started_at' => Date::parse($pending['started_at']),
                    'ended_at' => Date::parse($pending['ended_at']),
                ], deferrable: false);
            }
        }
    }

    /**
     * Start the unit of an Artisan command. Only the command the user ran
     * gets a span; commands it calls run inside it.
     */
    public function startCommand(?string $command): void
    {
        if (blank($command) || isset($this->units[self::COMMAND_UNIT])) {
            return;
        }

        $name = config('spectra.tracking.capture_origin', true) ? "artisan {$command}" : 'Artisan command';

        $this->startUnit(self::COMMAND_UNIT, SpanKind::Command, $name);
        $this->commandName = $command;
    }

    /**
     * Close the user's Artisan command span when that command, not one it called, ends.
     */
    public function finishCommand(?string $command): void
    {
        if ($command !== null && $command === $this->commandName) {
            $this->commandName = null;
            $this->finishUnit(self::COMMAND_UNIT);
        }
    }

    /**
     * Finish the HTTP request's unit once the app is terminating.
     */
    public function finishRequest(): void
    {
        $this->finishUnit(self::HTTP_UNIT);
    }

    /**
     * Open a Spectra::span() or Spectra::tool() block as the child of the current span.
     *
     * @param  array<string, mixed>  $attributes
     * @return string the new span's id, to close it with
     */
    public function open(string $name, string $traceId, ?string $upstreamParentSpanId, array $attributes = [], SpanKind $kind = SpanKind::Span): string
    {
        $spanId = $this->newSpanId();
        $parentSpanId = $this->parentFor($traceId, $upstreamParentSpanId);

        $this->spans[] = [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'trace_id' => $traceId,
            'name' => $name,
            'kind' => $kind,
            'origin' => $this->origin(),
            'started_at' => Date::now(),
            'attributes' => $attributes,
            'opened' => ++$this->opened,
        ];

        return $spanId;
    }

    /**
     * Close the span opened as $spanId (and any left open inside it) and save
     * it, with the attributes known only at its end added to its own.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function close(string $spanId, ?Throwable $error = null, array $attributes = []): void
    {
        while ($this->spans !== []) {
            $span = array_pop($this->spans);
            $isTarget = $span['span_id'] === $spanId;

            $this->writer->record($this->attributes(
                traceId: $span['trace_id'],
                spanId: $span['span_id'],
                parentSpanId: $span['parent_span_id'],
                name: $span['name'],
                kind: $span['kind'],
                origin: $span['origin'],
                startedAt: $span['started_at'],
                error: $isTarget ? $error : null,
                attributes: $isTarget ? [...$span['attributes'], ...$attributes] : $span['attributes'],
            ));

            if ($isTarget) {
                return;
            }
        }
    }

    /**
     * Save a span that already ended, timed by the caller: a tool Spectra
     * worked out from the calls around it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordFinished(
        string $name,
        SpanKind $kind,
        string $traceId,
        ?string $parentSpanId,
        CarbonInterface $startedAt,
        CarbonInterface $endedAt,
        array $attributes = [],
        ?string $errorMessage = null,
    ): void {
        $row = $this->attributes(
            traceId: $traceId,
            spanId: $this->newSpanId(),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kind,
            origin: $this->origin(),
            startedAt: $startedAt,
            error: null,
            attributes: $attributes,
            endedAt: $endedAt,
        );

        if ($errorMessage !== null) {
            $row['status'] = SpanStatus::Error->value;
            $row['error_message'] = Str::substr($errorMessage, 0, 10_000);
        }

        $this->writer->record($row);
    }

    /**
     * Get the parent of a new AI call or span in the given trace: the innermost
     * open span, else the current unit of work (which then gets saved),
     * else the span the work came from.
     */
    public function parentFor(?string $traceId, ?string $upstreamParentSpanId): ?string
    {
        return $this->currentSpanId($traceId, $upstreamParentSpanId, markUsed: true) ?? $upstreamParentSpanId;
    }

    /**
     * Get the span a queued job dispatched now runs under. Asking does not save
     * the unit of work: a request that only dispatches jobs stores no span.
     */
    public function parentForDispatch(?string $traceId, ?string $upstreamParentSpanId): ?string
    {
        return $this->currentSpanId($traceId, $upstreamParentSpanId, markUsed: false) ?? $upstreamParentSpanId;
    }

    /**
     * Get the spans a queued job dispatched now must save with its own, outermost
     * first: the current unit's, when nothing has saved it yet, after the ones
     * that unit was handed. Ask after {@see parentForDispatch()}, which gives
     * the unit its span id.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForDispatch(?string $traceId): array
    {
        $key = $this->units === [] ? null : array_key_last($this->units);
        $span = end($this->spans);

        // An open Spectra::span() block is saved when it closes, and its unit with it.
        if ($key === null || $traceId === null || ($span !== false && $span['opened'] > $this->units[$key]['opened'])) {
            return [];
        }

        $unit = $this->units[$key];

        // A unit in use saves its own span and the ones it was handed.
        if ($unit['used'] || $unit['span_id'] === null || $unit['trace_id'] !== $traceId) {
            return [];
        }

        $row = $this->attributes(
            traceId: $unit['trace_id'],
            spanId: $unit['span_id'],
            parentSpanId: $unit['parent_span_id'],
            name: $unit['name'],
            kind: $unit['kind'],
            origin: $unit['name'],
            startedAt: $unit['started_at'],
            error: null,
            attributes: [self::INFERRED_ATTRIBUTE => true],
        );

        // Plain strings, as the job payload carries them.
        $row['started_at'] = $row['started_at']->format('Y-m-d\TH:i:s.uP');
        $row['ended_at'] = $row['ended_at']->format('Y-m-d\TH:i:s.uP');

        return array_slice([...$unit['pending'], $row], -self::MAX_PENDING_SPANS);
    }

    /**
     * Get the innermost open span id in this trace, giving the current unit its root span on first use.
     */
    protected function currentSpanId(?string $traceId, ?string $upstreamParentSpanId, bool $markUsed): ?string
    {
        if ($traceId === null) {
            return null;
        }

        $span = end($this->spans);
        $key = $this->currentUnitKey();

        // Whichever opened last is innermost: a job run inside a span, or a span inside a job.
        if ($span !== false && ($key === null || $span['opened'] > $this->units[$key]['opened'])) {
            return $span['trace_id'] === $traceId ? $span['span_id'] : null;
        }

        if ($key === null) {
            return null;
        }

        $unit = &$this->units[$key];

        // Made on first use, with the trace and parent of that moment.
        if ($unit['span_id'] === null) {
            $unit['span_id'] = $this->newSpanId();
            $unit['trace_id'] = $traceId;
            $unit['parent_span_id'] = $upstreamParentSpanId;
            $unit['pending'] = collect(TraceContext::pendingSpans())
                ->filter(fn (array $pending): bool => $pending['trace_id'] === $traceId)
                ->values()
                ->all();
        }

        if ($unit['trace_id'] !== $traceId) {
            return null;
        }

        $spanId = $unit['span_id'];

        if ($markUsed) {
            $this->markUsed($key);
        }

        return $spanId;
    }

    /**
     * Mark the unit, and the outer units it runs under, as used so their spans
     * are saved: a synchronous job's span points at the request's span, so
     * that one must be saved too.
     */
    protected function markUsed(string $key): void
    {
        $next = $key;

        // A unit already in use had its outer units marked then.
        while ($next !== null && ! $this->units[$next]['used']) {
            $this->units[$next]['used'] = true;
            $next = $this->unitKeyOf($this->units[$next]['parent_span_id']);
        }
    }

    /** Get the key of the open unit whose span has this id. */
    protected function unitKeyOf(?string $spanId): ?string
    {
        foreach ($this->units as $key => $unit) {
            if ($spanId !== null && $unit['span_id'] === $spanId) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Get the key of the innermost open unit. An HTTP request has no start
     * event in the package, so its unit opens on first use, from the
     * request's own start time when the server gives one.
     */
    protected function currentUnitKey(): ?string
    {
        if ($this->units !== []) {
            return array_key_last($this->units);
        }

        if (app()->runningInConsole() || ! app()->has('request') || ! config('spectra.tracking.root_spans', true)) {
            return null;
        }

        $this->startUnit(self::HTTP_UNIT, SpanKind::Request, $this->origin() ?? 'HTTP request', $this->requestStartedAt());

        return self::HTTP_UNIT;
    }

    /**
     * Get when the HTTP request started, or now when the server time is missing or stale.
     */
    protected function requestStartedAt(): CarbonInterface
    {
        $startedAt = request()->server('REQUEST_TIME_FLOAT');

        // Octane workers serve many requests per process; trust only a recent start.
        if (is_numeric($startedAt) && microtime(true) - (float) $startedAt < 3600) {
            return Date::createFromTimestamp((float) $startedAt, date_default_timezone_get());
        }

        return Date::now();
    }

    /** Get where the current work came from, unless the app turned origins off. */
    protected function origin(): ?string
    {
        return config('spectra.tracking.capture_origin', true) ? app(TraceOrigin::class)->current() : null;
    }

    /**
     * Generate a random 16-hex-character span id, as W3C trace context uses.
     */
    protected function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Build the span row's attributes, timed from its start until it ended (now, by default).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function attributes(
        string $traceId,
        string $spanId,
        ?string $parentSpanId,
        string $name,
        SpanKind $kind,
        ?string $origin,
        CarbonInterface $startedAt,
        ?Throwable $error,
        array $attributes,
        ?CarbonInterface $endedAt = null,
    ): array {
        $endedAt ??= Date::now();

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'name' => Str::substr($name, 0, 255),
            'kind' => $kind->value,
            'origin' => $origin === null ? null : Str::substr($origin, 0, 255),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => max(0, (int) round($startedAt->diffInMilliseconds($endedAt))),
            'status' => ($error === null ? SpanStatus::Ok : SpanStatus::Error)->value,
            'error_type' => $error === null ? null : $error::class,
            'error_message' => $error === null ? null : Str::substr($error->getMessage(), 0, 10_000),
            'attributes' => $attributes === [] ? null : $attributes,
        ];
    }
}
