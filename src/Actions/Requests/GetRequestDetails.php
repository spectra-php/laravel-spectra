<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spectra\Actions\Scores\GetScores;
use Spectra\Actions\Traces\GetTraceDetails;
use Spectra\Data\Responses\RequestDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Playground\PlaygroundAccess;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\TraceSchema;

/**
 * Everything the request detail screen shows for one request: its payloads
 * (the only endpoint that re-exposes them), tags, model capabilities, media
 * URLs and the other calls in its trace.
 */
class GetRequestDetails
{
    /** Trace calls returned with a request; the requests list shows the rest. */
    private const TRACE_LIMIT = 100;

    /**
     * Create a new get request details action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
        private readonly GetTraceDetails $getTraceDetails,
        private readonly GetScores $getScores,
        private readonly TraceSchema $traceSchema,
    ) {}

    /**
     * Get the request with its payloads, tags, capabilities, media URLs and trace.
     */
    public function __invoke(string $id): RequestDetailsResponse
    {
        $request = SpectraRequest::query()->with('tags')->findOrFail($id);

        $request->append('formatted_expires_at');

        $details = $request->makeVisible(['request', 'response'])->toArray();
        $details['prompt'] = $request->prompt;
        $details['tags'] = $request->tags->pluck('name')->toArray();
        $details['model_capabilities'] = $this->pricingLookup->getCapabilities($request->provider ?? '', $request->model);

        if ($request->model_type === 'image') {
            $details['image_urls'] = $this->resolveImageUrls($id, $request);
        }

        if ($request->model_type === 'tts' && filled($request->media_storage_path)) {
            $basePath = config('spectra.dashboard.path', 'spectra');
            $details['audio_url'] = "/{$basePath}/api/requests/{$id}/audio";
            $details['audio_download_url'] = "/{$basePath}/api/requests/{$id}/audio/download";
        }

        if (in_array($request->model_type, ['image', 'tts', 'video'], true)) {
            $details['media_storage_enabled'] = (bool) config('spectra.storage.media.enabled');
        }

        $details['can_open_in_playground'] = PlaygroundAccess::allowed();
        $details['scores'] = ($this->getScores)(requestId: $request->id);

        // The trace card orders calls by started_at; until the trace
        // migrations run it is left out and the page says so.
        $details['trace_migrated'] = $this->traceSchema->hasTraceColumns();
        $details['trace'] = $details['trace_migrated'] && filled($request->trace_id)
            ? ($this->getTraceDetails)($request->trace_id, $request->id, self::TRACE_LIMIT)?->toArray()
            : null;

        return new RequestDetailsResponse(data: $details);
    }

    /**
     * Get the URLs of the request's images, from the media disk or the stored response.
     *
     * @return array<int, string>
     */
    private function resolveImageUrls(string $requestId, SpectraRequest $request): array
    {
        $basePath = config('spectra.dashboard.path', 'spectra');

        $media = $request->media_storage_path ?? [];

        if (filled($media)) {
            /** @var array<string, mixed> $media */
            return collect($media)->keys()->map(
                fn (string $index) => "/{$basePath}/api/requests/{$requestId}/images/{$index}"
            )->all();
        }

        $response = $request->response;
        $outputItems = Arr::wrap($response['output'] ?? []);

        $imageOutputs = collect($outputItems)
            ->filter(fn (mixed $item) => is_array($item) && ($item['type'] ?? '') === 'image_generation_call');

        if ($imageOutputs->isNotEmpty()) {
            return $imageOutputs->values()->keys()->map(
                fn (int $index) => "/{$basePath}/api/requests/{$requestId}/images/{$index}"
            )->all();
        }

        // Replicate's output is one image URL or a list of them.
        /** @var array<int, string> $urls */
        $urls = collect($outputItems)
            ->filter(fn (mixed $item) => is_string($item) && Str::isUrl($item, ['http', 'https']))
            ->values()
            ->all();

        foreach ($response['data'] ?? [] as $index => $item) {
            if (filled($item['b64_json'] ?? null) && $item['b64_json'] !== '[stripped]') {
                $urls[] = "/{$basePath}/api/requests/{$requestId}/images/{$index}";
            } elseif (filled($item['url'] ?? null)) {
                $urls[] = $item['url'];
            }
        }

        return $urls;
    }
}
