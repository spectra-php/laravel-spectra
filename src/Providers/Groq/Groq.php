<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq;

use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Groq\Handlers\ChatHandler;
use Spectra\Providers\Groq\Handlers\ResponsesHandler;
use Spectra\Providers\Groq\Handlers\SpeechHandler;
use Spectra\Providers\Groq\Handlers\TranscriptionHandler;
use Spectra\Providers\Provider;

/**
 * Groq: OpenAI-compatible chat, Responses, Whisper and Orpheus speech, all
 * under `/openai/v1/`.
 *
 * Groq puts its request id in `x_groq.id`, which is the only id a
 * transcription response carries. The flex tier costs the same as on-demand
 * and is only recorded for reference.
 */
class Groq extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    public function getProvider(): string
    {
        return 'groq';
    }

    public function getHosts(): array
    {
        return ['api.groq.com'];
    }

    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
            app(TranscriptionHandler::class),
            app(SpeechHandler::class),
            app(ResponsesHandler::class),
        ];
    }

    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $data = $this->toArray($response);
        $id = $data['id'] ?? $data['x_groq']['id'] ?? null;

        return filled($id) ? $id : null;
    }

    /** @param  array<string, mixed>  $requestData */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $this->tier($requestData['service_tier'] ?? null);
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $this->tier($responseData['service_tier'] ?? null);
    }

    protected function tier(mixed $serviceTier): ?string
    {
        return match ($serviceTier) {
            'flex' => PricingTier::Flex->value,
            'on_demand', 'default' => PricingTier::Standard->value,
            default => null,
        };
    }
}
