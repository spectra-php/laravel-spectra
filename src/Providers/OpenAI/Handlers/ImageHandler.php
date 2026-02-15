<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\StoresOpenAiImageMedia;

class ImageHandler implements Handler, HasMedia
{
    use MatchesEndpoints;
    use StoresOpenAiImageMedia;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return [
            '/v1/images/generations',
            '/v1/images/edits',
            '/v1/images/variations',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        return new Metrics(
            tokens: ($inputTokens + $outputTokens) > 0
                ? new TokenMetrics(
                    promptTokens: $inputTokens,
                    completionTokens: $outputTokens,
                )
                : null,
            image: new ImageMetrics(
                count: isset($responseData['data']) ? count($responseData['data']) : 0,
            ),
        );
    }

    /**
     * DALL-E responses don't include model in the response body,
     * so we fall back to the request data's model field.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }
}
