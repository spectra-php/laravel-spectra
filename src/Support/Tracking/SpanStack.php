<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
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
 * Bound as scoped: queue workers and Octane start each job and request with
 * an empty stack. A synchronous job pushes its unit on top of the request's.
 */
class SpanStack
{
    /** Keys of the HTTP request's and the Artisan command's units; jobs are keyed by job. */
    protected const HTTP_UNIT = 'http';

    protected const COMMAND_UNIT = 'command';

    /** The command the user ran, so a command it calls doesn't close its span. */
    protected ?string $commandName = null;

    /**
     * Open Spectra::span() blocks, innermost last.
     *
     * @var list<array{span_id: string, parent_span_id: ?string, trace_id: string, name: string, origin: ?string, started_at: CarbonInterface, attributes: array<string, mixed>, opened: int}>
     */
    protected array $spans = [];

    /**
     * Open units of work, innermost last.
     *
     * @var array<string, array{kind: SpanKind, name: string, started_at: CarbonInterface, span_id: ?string, parent_span_id: ?string, trace_id: ?string, used: bool, opened: int}>
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
     * A request, job or command began; it becomes a root span if an AI call
     * or span happens inside it.
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
        ];
    }

    /**
     * A request, job or command ended: save its span if anything inside used it.
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
    }

    /**
     * An Artisan command began. Only the command the user ran gets a span;
     * commands it calls run inside it.
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
     * The HTTP request ended (the app is terminating).
     */
    public function finishRequest(): void
    {
        $this->finishUnit(self::HTTP_UNIT);
    }

    /**
     * Open a Spectra::span() block as the child of the current span.
     *
     * @param  array<string, mixed>  $attributes
     * @return string the new span's id, to close it with
     */
    public function open(string $name, string $traceId, ?string $upstreamParentSpanId, array $attributes = []): string
    {
        $spanId = $this->newSpanId();
        $parentSpanId = $this->parentFor($traceId, $upstreamParentSpanId);

        $this->spans[] = [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'trace_id' => $traceId,
            'name' => $name,
            'origin' => $this->origin(),
            'started_at' => Date::now(),
            'attributes' => $attributes,
            'opened' => ++$this->opened,
        ];

        return $spanId;
    }

    /**
     * Close the span opened as $spanId (and any left open inside it) and save it.
     */
    public function close(string $spanId, ?Throwable $error = null): void
    {
        while ($this->spans !== []) {
            $span = array_pop($this->spans);
            $isTarget = $span['span_id'] === $spanId;

            $this->writer->record($this->attributes(
                traceId: $span['trace_id'],
                spanId: $span['span_id'],
                parentSpanId: $span['parent_span_id'],
                name: $span['name'],
                kind: SpanKind::Span,
                origin: $span['origin'],
                startedAt: $span['started_at'],
                error: $isTarget ? $error : null,
                attributes: $span['attributes'],
            ));

            if ($isTarget) {
                return;
            }
        }
    }

    /**
     * The parent of a new AI call or span in the given trace: the innermost
     * open span, else the current unit of work (which then gets saved),
     * else the span the work came from.
     */
    public function parentFor(?string $traceId, ?string $upstreamParentSpanId): ?string
    {
        return $this->currentSpanId($traceId, $upstreamParentSpanId, markUsed: true) ?? $upstreamParentSpanId;
    }

    /**
     * The span a queued job dispatched now runs under. Asking does not save
     * the unit of work: a request that only dispatches jobs stores no span.
     */
    public function parentForDispatch(?string $traceId, ?string $upstreamParentSpanId): ?string
    {
        return $this->currentSpanId($traceId, $upstreamParentSpanId, markUsed: false) ?? $upstreamParentSpanId;
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
     * Save the unit, and the outer unit it runs under: a synchronous job's
     * span points at the request's span, so that one must be saved too.
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

    /** The key of the open unit whose span has this id. */
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
     * The innermost open unit. An HTTP request has no start event in the
     * package, so its unit opens on first use, from the request's own start
     * time when the server gives one.
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

    /** Where the current work came from, unless the app turned origins off. */
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
     * Build the span row's attributes, timed from its start until now.
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
    ): array {
        $endedAt = Date::now();

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'name' => mb_substr($name, 0, 255),
            'kind' => $kind->value,
            'origin' => $origin === null ? null : mb_substr($origin, 0, 255),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => max(0, (int) round($startedAt->diffInMilliseconds($endedAt))),
            'status' => ($error === null ? SpanStatus::Ok : SpanStatus::Error)->value,
            'error_type' => $error === null ? null : $error::class,
            'error_message' => $error === null ? null : mb_substr($error->getMessage(), 0, 10_000),
            'attributes' => $attributes === [] ? null : $attributes,
        ];
    }
}
