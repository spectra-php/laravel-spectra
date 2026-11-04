<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Groq prices, from console.groq.com/docs/models and the model pages.
 *
 * Production model ids carry the publisher prefix (`openai/gpt-oss-120b`); the
 * old unprefixed ids stay as aliases. Prompt caching (GPT-OSS models) bills
 * cached input at 50% off. The Batch API is 50% off and does not stack with
 * the cache discount, so batch cached input bills at the batch input price.
 * Flex costs the same as on-demand. Retirement dates come from
 * console.groq.com/docs/deprecations; the Llama 3.x shutdown on 2026-08-16
 * applies to on-demand use (enterprise contracts are not affected).
 *
 * Whisper is priced per hour of audio; the catalog stores it per minute (the
 * hourly price divided by 60). Groq bills at least 10 seconds per
 * transcription, which the Groq TranscriptionHandler applies to the recorded
 * duration. Orpheus text to speech is cents per 1M characters.
 */
class GroqPricing extends ProviderPricing
{
    /**
     * Get the provider slug the Groq prices are recorded under.
     */
    public function provider(): string
    {
        return 'groq';
    }

    /**
     * Get the Groq pricing pages `spectra:pricing-scrape` reads.
     */
    public function sources(): array
    {
        return ['https://console.groq.com/docs/models'];
    }

    /**
     * Register the built-in Groq models and their prices.
     */
    protected function define(): void
    {
        $this->model('openai/gpt-oss-120b', fn ($m) => $m
            ->displayName('GPT OSS 120B')
            ->aliases('gpt-oss-120b')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60, cachedInputPrice: 7.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 30, cachedInputPrice: 7.5));

        $this->model('openai/gpt-oss-20b', fn ($m) => $m
            ->displayName('GPT OSS 20B')
            ->aliases('gpt-oss-20b')
            ->canGenerateText()
            ->tier('standard', inputPrice: 7.5, outputPrice: 30, cachedInputPrice: 3.75)
            ->tier('batch', inputPrice: 3.75, outputPrice: 15, cachedInputPrice: 3.75));

        $this->model('openai/gpt-oss-safeguard-20b', fn ($m) => $m
            ->displayName('GPT OSS Safeguard 20B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 7.5, outputPrice: 30, cachedInputPrice: 3.75));

        $this->model('qwen/qwen3.8-27b', fn ($m) => $m
            ->displayName('Qwen3.8 27B')
            ->canGenerateText()
            ->tier('standard', inputPrice: 80, outputPrice: 400));

        $this->model('meta-llama/llama-prompt-guard-2-22m', fn ($m) => $m
            ->displayName('Llama Prompt Guard 2 22M')
            ->canGenerateText()
            ->tier('standard', inputPrice: 3, outputPrice: 3));

        $this->model('meta-llama/llama-prompt-guard-2-86m', fn ($m) => $m
            ->displayName('Llama Prompt Guard 2 86M')
            ->canGenerateText()
            ->tier('standard', inputPrice: 4, outputPrice: 4));

        $this->model('llama-3.1-8b-instant', fn ($m) => $m
            ->displayName('Llama 3.1 8B Instant')
            ->retiredOn('2026-08-16')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 8)
            ->tier('batch', inputPrice: 2.5, outputPrice: 4));

        $this->model('llama-3.3-70b-versatile', fn ($m) => $m
            ->displayName('Llama 3.3 70B Versatile')
            ->retiredOn('2026-08-16')
            ->canGenerateText()
            ->tier('standard', inputPrice: 59, outputPrice: 79)
            ->tier('batch', inputPrice: 29.5, outputPrice: 39.5));

        $this->model('meta-llama/llama-4-scout-17b-16e-instruct', fn ($m) => $m
            ->displayName('Llama 4 Scout')
            ->aliases('llama-4-scout-17b-16e-instruct')
            ->retiredOn('2026-07-17')
            ->canGenerateText()
            ->tier('standard', inputPrice: 11, outputPrice: 34));

        $this->model('meta-llama/llama-4-maverick-17b-128e-instruct', fn ($m) => $m
            ->displayName('Llama 4 Maverick')
            ->aliases('llama-4-maverick-17b-128e-instruct')
            ->retiredOn('2026-03-09')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 60));

        $this->model('meta-llama/llama-guard-4-12b', fn ($m) => $m
            ->displayName('Llama Guard 4 12B')
            ->aliases('llama-guard-4-12b')
            ->retiredOn('2026-03-05')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('qwen/qwen3-32b', fn ($m) => $m
            ->displayName('Qwen3 32B')
            ->aliases('qwen3-32b')
            ->retiredOn('2026-07-17')
            ->canGenerateText()
            ->tier('standard', inputPrice: 29, outputPrice: 59));

        // The original Kimi K2 (retired 2025-10-10) had the same price.
        $this->model('moonshotai/kimi-k2-instruct-0905', fn ($m) => $m
            ->displayName('Kimi K2')
            ->aliases('kimi-k2-0905', 'moonshotai/kimi-k2-instruct')
            ->retiredOn('2026-04-15')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 300));

        $this->model('gemma2-9b-it', fn ($m) => $m
            ->displayName('Gemma 2 9B')
            ->retiredOn('2025-10-08')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20));

        $this->model('qwen-qwq-32b', fn ($m) => $m
            ->displayName('QwQ 32B')
            ->retiredOn('2025-07-14')
            ->canGenerateText()
            ->tier('standard', inputPrice: 29, outputPrice: 39));

        // Whisper is priced per hour of audio ($0.111 and $0.04), stored per minute.
        $this->model('whisper-large-v3', fn ($m) => $m
            ->displayName('Whisper Large v3')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 11.1 / 60)
            ->tier('batch', pricePerUnit: 11.1 / 120));

        $this->model('whisper-large-v3-turbo', fn ($m) => $m
            ->displayName('Whisper Large v3 Turbo')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 4 / 60)
            ->tier('batch', pricePerUnit: 4 / 120));

        $this->model('distil-whisper-large-v3-en', fn ($m) => $m
            ->displayName('Distil-Whisper Large v3 EN')
            ->retiredOn('2025-08-23')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.033));

        // Text to speech, per 1M input characters.
        $this->model('canopylabs/orpheus-v1-english', fn ($m) => $m
            ->displayName('Orpheus V1 English')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 2200));

        $this->model('canopylabs/orpheus-arabic-saudi', fn ($m) => $m
            ->displayName('Orpheus Arabic Saudi')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 4000));
    }
}
