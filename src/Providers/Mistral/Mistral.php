<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral;

use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Enums\PricingTier;
use Spectra\Providers\Mistral\Handlers\ChatHandler;
use Spectra\Providers\Mistral\Handlers\EmbeddingHandler;
use Spectra\Providers\Mistral\Handlers\OcrHandler;
use Spectra\Providers\Mistral\Handlers\SpeechHandler;
use Spectra\Providers\Mistral\Handlers\TranscriptionHandler;
use Spectra\Providers\Provider;

/**
 * Mistral AI: chat / FIM / agents, embeddings, Voxtral speech, and OCR.
 *
 * Chat requests can ask for priority capacity (`service_tier`); the response
 * reports the tier that served it in `usage.service_tier`.
 */
class Mistral extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    /**
     * Get the slug Mistral requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'mistral';
    }

    /**
     * Get the hosts the Mistral and Codestral APIs are served from.
     */
    public function getHosts(): array
    {
        return ['api.mistral.ai', 'codestral.mistral.ai'];
    }

    /**
     * Get the handlers for Mistral embeddings, chat, transcription, speech and OCR.
     */
    public function handlers(): array
    {
        return [
            app(EmbeddingHandler::class),
            app(ChatHandler::class),
            app(TranscriptionHandler::class),
            app(SpeechHandler::class),
            app(OcrHandler::class),
        ];
    }

    /**
     * Get the pricing tier the request's `service_tier` asked for.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        return $this->tier($requestData['service_tier'] ?? null);
    }

    /**
     * Get the pricing tier the response reports in `usage.service_tier`.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        return $this->tier($responseData['usage']['service_tier'] ?? $responseData['service_tier'] ?? null);
    }

    /**
     * Map a Mistral `service_tier` value to a Spectra pricing tier.
     */
    protected function tier(mixed $serviceTier): ?string
    {
        return match ($serviceTier) {
            'priority' => PricingTier::Priority->value,
            'standard' => PricingTier::Standard->value,
            default => null,
        };
    }
}
