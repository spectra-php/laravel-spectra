<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Illuminate\Support\Carbon;
use Spectra\Data\Responses\RequestDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\AggregatesSuccessfulRequests;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Everything the request detail screen shows for one request: its payloads
 * (the only endpoint that re-exposes them), tags, model capabilities, media
 * URLs and the other calls in its trace.
 */
class GetRequestDetails
{
    use AggregatesSuccessfulRequests;

    /** Trace calls returned with a request; the requests list shows the rest. */
    private const TRACE_LIMIT = 100;

    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    public function __invoke(string $id): RequestDetailsResponse
    {
        $request = SpectraRequest::query()->with('tags')->findOrFail($id);

        $request->append('formatted_expires_at');

        $data = $request->makeVisible(['request', 'response'])->toArray();
        $data['prompt'] = $request->prompt;
        $data['tags'] = $request->tags->pluck('name')->toArray();
        $data['model_capabilities'] = $this->pricingLookup->getCapabilities($request->provider ?? '', $request->model);

        if ($request->model_type === 'image') {
            $data['image_urls'] = $this->resolveImageUrls($id, $request);
        }

        if ($request->model_type === 'tts' && ! empty($request->media_storage_path)) {
            $basePath = config('spectra.dashboard.path', 'spectra');
            $data['audio_url'] = "/{$basePath}/api/requests/{$id}/audio";
            $data['audio_download_url'] = "/{$basePath}/api/requests/{$id}/audio/download";
        }

        if (in_array($request->model_type, ['image', 'tts', 'video'], true)) {
            $data['media_storage_enabled'] = (bool) config('spectra.storage.media.enabled');
        }

        $data['trace'] = $this->resolveTrace($request);

        return new RequestDetailsResponse(data: $data);
    }

    /**
     * The other AI calls that share this request's trace id (the same HTTP
     * request, job, or command), in the order they were made.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTrace(SpectraRequest $request): ?array
    {
        if (blank($request->trace_id)) {
            return null;
        }

        $query = fn () => SpectraRequest::query()->where('trace_id', $request->trace_id);

        $totals = $query()
            ->selectRaw('count(*) as request_count')
            ->selectRaw($this->successfulCostSum('total_cost'))
            ->selectRaw('sum(prompt_tokens + completion_tokens) as total_tokens')
            ->selectRaw('min(created_at) as first_started_at')
            ->selectRaw('max(coalesce(completed_at, created_at)) as last_completed_at')
            ->toBase()
            ->first();

        $count = (int) ($totals->request_count ?? 0);

        // Calls made before this one; ties on created_at fall back to the id.
        $position = $query()
            ->where(fn ($where) => $where
                ->where('created_at', '<', $request->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $request->created_at)->where('id', '<', $request->id)))
            ->count() + 1;

        $requests = $query()
            ->select([
                'id', 'provider', 'model', 'model_type', 'endpoint', 'status_code', 'error_type', 'is_streaming',
                'prompt_tokens', 'completion_tokens', 'total_cost_in_cents', 'latency_ms', 'created_at', 'completed_at',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::TRACE_LIMIT)
            ->get()
            ->map(fn (SpectraRequest $step) => [
                'id' => $step->id,
                'provider' => $step->provider,
                'provider_display_name' => $step->provider_display_name,
                'model' => $step->model,
                'model_type' => $step->model_type,
                'endpoint' => $step->endpoint,
                'status_code' => $step->status_code,
                'is_error' => $step->error_type !== null || ($step->status_code !== null && $step->status_code >= 400),
                'is_streaming' => $step->is_streaming,
                'total_tokens' => $step->prompt_tokens + $step->completion_tokens,
                'total_cost_in_cents' => (float) $step->total_cost_in_cents,
                'latency_ms' => $step->latency_ms,
                'created_at' => $step->created_at?->toIso8601String(),
                'offset_ms' => $this->offsetMs($totals->first_started_at ?? null, $step->created_at),
                'is_current' => $step->id === $request->id,
            ])
            ->all();

        return [
            'trace_id' => $request->trace_id,
            'parent_span_id' => $request->parent_span_id,
            'request_count' => $count,
            'position' => $position,
            'total_cost_in_cents' => (float) ($totals->total_cost ?? 0),
            'total_tokens' => (int) ($totals->total_tokens ?? 0),
            // Timestamps are stored to the second, so also count each call's own latency.
            'duration_ms' => max(
                $this->offsetMs($totals->first_started_at ?? null, $totals->last_completed_at ?? null) ?? 0,
                ...array_map(fn (array $step) => ($step['offset_ms'] ?? 0) + ($step['latency_ms'] ?? 0), $requests ?: [['offset_ms' => 0, 'latency_ms' => 0]]),
            ),
            'truncated' => $count > count($requests),
            'requests' => $requests,
        ];
    }

    /**
     * Milliseconds from the start of the trace to the given time.
     */
    private function offsetMs(mixed $from, mixed $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, (int) Carbon::parse($from)->diffInMilliseconds(Carbon::parse($to)));
    }

    /**
     * @return array<int, string>
     */
    private function resolveImageUrls(string $requestId, SpectraRequest $request): array
    {
        $basePath = config('spectra.dashboard.path', 'spectra');

        $media = $request->media_storage_path ?? [];

        if (! empty($media)) {
            /** @var array<string, mixed> $media */
            return collect($media)->keys()->map(
                fn (string $index) => "/{$basePath}/api/requests/{$requestId}/images/{$index}"
            )->all();
        }

        $response = $request->response;
        $urls = [];

        /** @var array<int, array<string, mixed>> $outputItems */
        $outputItems = $response['output'] ?? [];
        $imageOutputs = collect($outputItems)
            ->filter(fn (array $item) => ($item['type'] ?? '') === 'image_generation_call');

        if ($imageOutputs->isNotEmpty()) {
            foreach ($imageOutputs->values() as $index => $item) {
                $urls[] = "/{$basePath}/api/requests/{$requestId}/images/{$index}";
            }

            return $urls;
        }

        foreach ($response['data'] ?? [] as $index => $item) {
            if (! empty($item['b64_json']) && $item['b64_json'] !== '[stripped]') {
                $urls[] = "/{$basePath}/api/requests/{$requestId}/images/{$index}";
            } elseif (! empty($item['url'])) {
                $urls[] = $item['url'];
            }
        }

        return $urls;
    }
}
