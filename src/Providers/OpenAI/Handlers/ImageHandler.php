<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Providers\OpenAI\StoresOpenAiImageMedia;
use Spectra\Providers\OpenAI\Streaming\ImageStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Images API: generations, edits and variations.
 *
 * GPT Image models are priced per token (text and image input, image
 * output); DALL-E models per image, with HD quality as the `hd` tier. The
 * response has no `model` field, so the model comes from the request.
 */
class ImageHandler implements ExtractsModelFromResponse, Handler, HasMedia, StreamsResponse
{
    use ExtractsModelField;
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
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];
        $tokens = OpenAiUsage::fromImages($usage);

        return new Metrics(
            tokens: $tokens->totalTokens() > 0 ? $tokens : null,
            image: new ImageMetrics(
                count: is_array($responseData['data'] ?? null) ? count($responseData['data']) : 0,
            ),
        );
    }

    public function streamingHandler(): StreamHandler
    {
        return new ImageStreaming;
    }
}
