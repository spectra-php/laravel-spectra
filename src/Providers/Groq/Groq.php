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
    /**
     * Get the provider's slug.
     */
    public function getProvider(): string
    {
        return 'groq';
    }

    /**
     * Get the hosts Groq's API is served from.
     */
    public function getHosts(): array
    {
        return ['api.groq.com'];
    }

    /**
     * Get the handlers for Groq's endpoints.
     */
    public function handlers(): array
    {
        return [
            app(ChatHandler::class),
            app(TranscriptionHandler::class),
            app(SpeechHandler::class),
            app(ResponsesHandler::class),
        ];
    }

    /**
     * Get the response id from `id`, or from `x_groq.id` when the body has none.
     */
    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $data = $this->toArray($response);
        $id = $data['id'] ?? $data['x_groq']['id'] ?? null;

        return filled($id) ? $id : null;
    }

    /**
     * Get the pricing tier from the request's `service_tier`.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $this->tier($requestData['service_tier'] ?? null);
    }

    /**
     * Get the pricing tier from the response's `service_tier`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $this->tier($responseData['service_tier'] ?? null);
    }

    /**
     * Map a Groq service tier to a PricingTier value.
     */
    protected function tier(mixed $serviceTier): ?string
    {
        return match ($serviceTier) {
            'flex' => PricingTier::Flex->value,
            'on_demand', 'default' => PricingTier::Standard->value,
            default => null,
        };
    }
}
