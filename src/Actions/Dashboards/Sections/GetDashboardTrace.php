<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spectra\Data\Responses\DashboardTraceResponse;
use Spectra\Enums\SpanKind;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Dashboards\DashboardScope;

/**
 * One of the trackable's traces for a shared dashboard. Its columns are a fixed
 * list, so origins, endpoints, tool arguments and results are never read, and the
 * names of the app's routes and jobs are replaced.
 */
class GetDashboardTrace
{
    /** Calls and spans listed; a trace rarely has more. */
    private const LIMIT = 500;

    /** ISO 8601 with milliseconds: calls of one trace are often under a second apart. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Get the trace, or null unless every call in it is the trackable's.
     */
    public function __invoke(DashboardScope $scope, string $traceId, bool $showCosts, bool $showMessages): ?DashboardTraceResponse
    {
        if (! $scope->owns('trace_id', $traceId)) {
            return null;
        }

        $total = $scope->calls()->where('trace_id', $traceId)->count();

        $calls = $scope->calls()
            ->where('trace_id', $traceId)
            ->select([
                'id', 'provider', 'model', 'model_type', 'span_id', 'parent_span_id', 'status_code', 'error_type',
                'is_streaming', 'prompt_tokens', 'completion_tokens', 'total_cost_in_cents', 'latency_ms',
                'created_at', 'started_at',
            ])
            ->orderByRaw('COALESCE(started_at, created_at)')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $spans = $this->spans($traceId);

        $start = collect([$calls->map(fn (SpectraRequest $call) => $this->startOf($call))->min(), $spans->min('started_at')])->filter()->min();

        $requests = $calls->map(fn (SpectraRequest $call) => [
            'id' => $call->id,
            'provider' => $call->provider,
            'provider_display_name' => $call->provider_display_name,
            'model' => $call->model,
            'model_type' => $call->model_type,
            'endpoint' => null,
            'origin' => null,
            'span_id' => $call->span_id,
            'parent_span_id' => $call->parent_span_id,
            'status_code' => $call->status_code,
            'is_error' => $call->isFailed(),
            'is_streaming' => $call->is_streaming,
            'total_tokens' => $call->prompt_tokens + $call->completion_tokens,
            'total_cost_in_cents' => $showCosts ? $call->total_cost_in_cents : null,
            'latency_ms' => $call->latency_ms,
            'created_at' => $call->created_at?->toIso8601String(),
            'started_at' => $this->startOf($call)?->format(self::ISO_MILLISECONDS),
            'offset_ms' => $this->offset($start, $this->startOf($call)),
            'is_current' => false,
        ])->values()->all();

        $spanRows = $spans->map(fn (SpectraSpan $span) => [
            'span_id' => $span->span_id,
            'parent_span_id' => $span->parent_span_id,
            'name' => match ($span->kind) {
                SpanKind::Request => 'Request',
                SpanKind::Job => 'Background job',
                SpanKind::Command => 'Command',
                default => $span->name,
            },
            'kind' => $span->kind->value,
            'origin' => null,
            'is_error' => $span->isFailed(),
            'error_message' => $showMessages ? $span->error_message : null,
            'attributes' => (object) [],
            'started_at' => $span->started_at->format(self::ISO_MILLISECONDS),
            'offset_ms' => $this->offset($start, $span->started_at),
            'duration_ms' => $span->duration_ms,
        ])->values()->all();

        $ends = [0, ...array_map(fn (array $call) => ($call['offset_ms'] ?? 0) + ($call['latency_ms'] ?? 0), $requests), ...array_map(fn (array $span) => ($span['offset_ms'] ?? 0) + $span['duration_ms'], $spanRows)];
        $callEnds = array_map(fn (array $call) => ($call['offset_ms'] ?? 0) + ($call['latency_ms'] ?? 0), $requests);

        return new DashboardTraceResponse(
            trace_id: $traceId,
            request_count: $total,
            failed_count: $calls->filter(fn (SpectraRequest $call) => $call->isFailed())->count(),
            total_cost_in_cents: $showCosts ? (float) $calls->filter(fn (SpectraRequest $call) => $call->isSuccessful())->sum('total_cost_in_cents') : null,
            total_tokens: $calls->sum(fn (SpectraRequest $call) => $call->prompt_tokens + $call->completion_tokens),
            started_at: $start?->format(self::ISO_MILLISECONDS),
            duration_ms: $callEnds === [] ? 0 : max($callEnds),
            timeline_ms: max($ends),
            truncated: $total > $calls->count(),
            requests: $requests,
            spans: $spanRows,
        );
    }

    /**
     * Get the trace's spans, without their attributes; empty until the spans migration runs.
     *
     * @return Collection<int, SpectraSpan>
     */
    private function spans(string $traceId): Collection
    {
        return rescue(
            fn () => SpectraSpan::query()
                ->where('trace_id', $traceId)
                ->select(['span_id', 'parent_span_id', 'name', 'kind', 'status', 'error_message', 'started_at', 'ended_at', 'duration_ms'])
                ->oldest('started_at')
                ->limit(self::LIMIT)
                ->get()
                ->toBase(),
            fn () => new Collection,
            report: false,
        );
    }

    /**
     * Get when the call started, falling back to when it was recorded.
     */
    private function startOf(SpectraRequest $call): ?CarbonInterface
    {
        return $call->started_at ?? $call->created_at;
    }

    /**
     * Get the milliseconds from the start of the trace to the given time.
     */
    private function offset(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from === null || $to === null ? null : max(0, (int) round($from->diffInMilliseconds($to)));
    }
}
