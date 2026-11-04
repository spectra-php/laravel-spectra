<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Support\MediaPersister;

/**
 * Responses API answers that used the `image_generation` tool.
 *
 * The response `usage` is the mainline model's (e.g. gpt-5.4) token usage,
 * so it is priced as that model; the response does not report the image
 * model's own token usage.
 */
class ResponsesImageHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, HasMedia, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Get the Responses API endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/v1/responses'];
    }

    /**
     * Determine if the body is a Responses API answer with an `image_generation` call.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        if (($data['object'] ?? null) !== 'response') {
            return false;
        }

        return $this->hasImageGenerationOutput($data);
    }

    /**
     * Get the mainline model's token usage and the number of generated images.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? null;

        return new Metrics(
            tokens: is_array($usage) ? OpenAiUsage::fromResponses($usage) : new TokenMetrics,
            image: new ImageMetrics(
                count: $this->countImageGenerationCalls($responseData),
            ),
        );
    }

    /**
     * Get the message text, with a placeholder or revised prompt for each image.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        foreach ($this->outputItems($response) as $outputItem) {
            if (($outputItem['type'] ?? null) === 'image_generation_call') {
                $parts[] = blank($outputItem['result'] ?? null) && filled($outputItem['revised_prompt'] ?? null)
                    ? $outputItem['revised_prompt']
                    : '[base64 image data]';
            }

            if (($outputItem['type'] ?? null) === 'message' && is_array($outputItem['content'] ?? null)) {
                foreach ($outputItem['content'] as $contentItem) {
                    if (filled($contentItem['text'] ?? null)) {
                        $parts[] = $contentItem['text'];
                    }
                }
            }
        }

        return filled($parts) ? implode("\n", $parts) : null;
    }

    /**
     * Get the response's status as the finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return $response['status'] ?? null;
    }

    /**
     * Store the base64 images of the `image_generation` calls and return their paths.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];
        $index = 0;

        foreach ($this->outputItems($responseData) as $outputItem) {
            if (($outputItem['type'] ?? null) !== 'image_generation_call') {
                continue;
            }

            $b64 = $outputItem['result'] ?? null;

            if (filled($b64)) {
                $content = base64_decode($b64, true);

                if ($content === false || blank($content)) {
                    continue;
                }

                $stored[] = $persister->store($requestId, $index, $content, 'png');
                $index++;
            }
        }

        return $stored;
    }

    /**
     * Determine if the response's output has an `image_generation` call.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasImageGenerationOutput(array $data): bool
    {
        return $this->countImageGenerationCalls($data) > 0;
    }

    /**
     * Count the `image_generation` calls in the response's output.
     *
     * @param  array<string, mixed>  $data
     */
    private function countImageGenerationCalls(array $data): int
    {
        return collect($this->outputItems($data))
            ->filter(fn (array $item) => ($item['type'] ?? null) === 'image_generation_call')
            ->count();
    }

    /**
     * Get the response's output items.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<mixed>>
     */
    private function outputItems(array $data): array
    {
        $output = $data['output'] ?? null;

        if (! is_array($output)) {
            return [];
        }

        return collect($output)->filter(fn (mixed $item) => is_array($item))->values()->all();
    }
}
