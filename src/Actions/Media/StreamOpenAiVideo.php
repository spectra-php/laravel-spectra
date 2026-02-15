<?php

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Http;
use Spectra\Support\ApiKeyResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamOpenAiVideo
{
    public function __construct(
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {}

    public function __invoke(string $videoId): StreamedResponse
    {
        $apiKey = $this->apiKeyResolver->resolve('openai');

        abort_unless((bool) $apiKey, 500, 'OpenAI API key not configured.');

        $response = Http::withoutAITracking()
            ->withToken($apiKey)
            ->throw()
            ->withOptions(['stream' => true])
            ->get("https://api.openai.com/v1/videos/{$videoId}/content");

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => "attachment; filename=\"{$videoId}.mp4\"",
        ]);
    }
}
