<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * ElevenLabs API prices. API usage is billed in US dollars, not credits.
 *
 * Text to speech is cents per 1M characters ($0.08 per 1,000 characters =
 * 8000); speech to text, sound effects and music are cents per minute.
 * Scribe add-ons (keyterm prompting $0.05/hour, transcript editing
 * $0.07/hour) are not included.
 *
 * Source: https://elevenlabs.io/pricing/api and
 * https://elevenlabs.io/docs/overview/models (last reviewed 2026-10-15).
 */
class ElevenLabsPricing extends ProviderPricing
{
    /**
     * Get the provider slug the ElevenLabs prices are recorded under.
     */
    public function provider(): string
    {
        return 'elevenlabs';
    }

    /**
     * Get the ElevenLabs pricing pages `spectra:pricing-scrape` reads.
     */
    public function sources(): array
    {
        return ['https://elevenlabs.io/pricing/api'];
    }

    /**
     * Register the built-in ElevenLabs models and their prices.
     */
    protected function define(): void
    {
        // Text to speech
        $this->textToSpeech('eleven_v4', 'Eleven v4', 8000);
        $this->textToSpeech('eleven_v4_turbo', 'Eleven v4 Turbo', 4000);
        $this->textToSpeech('eleven_v3', 'Eleven v3', 8000);
        $this->textToSpeech('eleven_v3_conversational', 'Eleven v3 Conversational', 4000);
        $this->textToSpeech('eleven_multilingual_v2', 'Eleven Multilingual v2', 8000);
        $this->textToSpeech('eleven_flash_v2_5', 'Eleven Flash v2.5', 4000);
        $this->textToSpeech('eleven_flash_v2', 'Eleven Flash v2', 4000);

        // Deprecated: "functionally equivalent" to the Flash models and billed
        // like them, but still their own model ids (no redirect documented).
        $this->textToSpeech('eleven_turbo_v2_5', 'Eleven Turbo v2.5', 4000);
        $this->textToSpeech('eleven_turbo_v2', 'Eleven Turbo v2', 4000);

        // Speech to text: $0.22 per hour of audio.
        $this->model('scribe_v2', fn ($m) => $m
            ->displayName('Scribe v2')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 22 / 60));

        $this->model('scribe_v2_medical', fn ($m) => $m
            ->displayName('Scribe v2 Medical')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 22 / 60));

        // Sound effects: $0.12 per minute.
        $this->model('eleven_text_to_sound_v2', fn ($m) => $m
            ->displayName('Eleven Sound Effects v2')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 12));

        // Music: $0.15 per minute.
        foreach (['music_v1' => 'Eleven Music v1', 'music_v2' => 'Eleven Music v2', 'music_v2_5' => 'Eleven Music v2.5'] as $id => $name) {
            $this->model($id, fn ($m) => $m
                ->displayName($name)
                ->type('audio')
                ->pricingUnit('minute')
                ->canGenerateAudio()
                ->tier('standard', pricePerUnit: 15));
        }
    }

    /**
     * Register a text-to-speech model billed per 1M characters.
     *
     * @param  float  $centsPerMillionCharacters  e.g. 8000 for $0.08 per 1,000 characters
     */
    protected function textToSpeech(string $id, string $displayName, float $centsPerMillionCharacters): void
    {
        $this->model($id, fn ($m) => $m
            ->displayName($displayName)
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: $centsPerMillionCharacters));
    }
}
