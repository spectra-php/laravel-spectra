<?php

namespace Spectra\Providers\Replicate\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class TextHandler implements Handler, HasFinishReason, MatchesResponseShape
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v1/models/{owner}/{model}/predictions'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['metrics']['input_token_count'] ?? 0),
                completionTokens: (int) ($responseData['metrics']['output_token_count'] ?? 0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $output = $response['output'] ?? null;

        if (is_string($output)) {
            return $output;
        }

        if (is_array($output) && ! empty($output)) {
            // Text models return array of string tokens
            if (is_string($output[0] ?? null)) {
                return implode('', $output);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['status'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        if (! isset($data['urls'], $data['status'])) {
            return false;
        }

        $output = $data['output'] ?? null;

        // Text models return string or array of string tokens
        if (is_string($output)) {
            return true;
        }

        if (is_array($output) && ! empty($output) && is_string($output[0] ?? null)) {
            // Check it's not a URL (which would indicate image/video output)
            return ! filter_var($output[0], FILTER_VALIDATE_URL);
        }

        return false;
    }
}
