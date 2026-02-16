<?php

namespace Spectra\Actions\Requests;

use Spectra\Data\Responses\RequestDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\PricingLookup;

class GetRequestDetails
{
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    public function __invoke(string $id): RequestDetailsResponse
    {
        $request = SpectraRequest::with('tags')->findOrFail($id);

        $response = $request->response;

        $data = $request->toArray();
        $data['prompt'] = $request->prompt;
        $data['response'] = $response;
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

        return new RequestDetailsResponse(data: $data);
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
