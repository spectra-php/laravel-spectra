<?php

namespace Spectra\Providers\OpenAI;

use Spectra\Contracts\ExtractsPricingTierFromRequest;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Providers\OpenAI\Handlers\EmbeddingHandler;
use Spectra\Providers\OpenAI\Handlers\ImageHandler;
use Spectra\Providers\OpenAI\Handlers\ResponsesImageHandler;
use Spectra\Providers\OpenAI\Handlers\SpeechHandler;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\Handlers\TranscriptionHandler;
use Spectra\Providers\OpenAI\Handlers\VideoHandler;
use Spectra\Providers\Provider;

/**
 * OpenAI provider — thin coordinator that delegates all extraction to handlers.
 *
 * Each handler knows its endpoints, response shapes, and how to extract
 * metrics/content for its capability type (text, images, embeddings, audio).
 */
class OpenAI extends Provider implements ExtractsPricingTierFromRequest, ExtractsPricingTierFromResponse
{
    public function getProvider(): string
    {
        return 'openai';
    }

    public function getHosts(): array
    {
        return ['api.openai.com'];
    }

    /**
     * Order matters: when multiple handlers match the same endpoint,
     * specialists (MatchesResponseShape) are checked in reverse order.
     * Place specialist handlers after the default handler they override.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(VideoHandler::class),
            app(EmbeddingHandler::class),
            app(TranscriptionHandler::class),
            app(SpeechHandler::class),
            app(TextHandler::class),
            app(ResponsesImageHandler::class), // Specialist: overrides TextHandler for /v1/responses with image output
        ];
    }

    /** @param  array<string, mixed>  $requestData */
    public function extractPricingTierFromRequest(array $requestData): ?string
    {
        $serviceTier = $requestData['service_tier'] ?? null;

        return $serviceTier !== null ? $this->mapServiceTier($serviceTier) : null;
    }

    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string
    {
        $serviceTier = $responseData['service_tier'] ?? null;

        return $serviceTier !== null ? $this->mapServiceTier($serviceTier) : null;
    }

    protected function mapServiceTier(string $serviceTier): string
    {
        return match ($serviceTier) {
            'flex' => 'flex',
            'priority' => 'priority',
            'default', 'auto' => config("spectra.costs.provider_settings.{$this->getProvider()}.default_tier", 'standard'),
            default => $serviceTier,
        };
    }
}
