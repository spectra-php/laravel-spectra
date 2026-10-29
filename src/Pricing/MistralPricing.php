<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Mistral AI prices, from docs.mistral.ai (API pricing page and model cards).
 *
 * Cached input is 10% of the input price on every model the pricing page
 * lists (GLM 5.3 Flash: 20%, DeepSeek V4.1 Flash: 2.3%). The Batch API is 50% off for models that
 * support batching, and the batch discount applies to every token type.
 * Retired models predate prompt caching, so they carry no cached price and
 * bill cached tokens at the input rate. Model cards list each model's dated
 * ids (`mistral-medium-2508`, ...); those resolve through aliases or their own
 * entry when the price differs. Retirement dates are the ones the model cards
 * publish. A priority `service_tier` is recorded, but Mistral publishes no
 * separate priority price, so it bills at the standard rate here.
 */
class MistralPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'mistral';
    }

    protected function define(): void
    {
        // Mistral Large 4 is in public preview. Its launch sale ($0.68 / $2.09,
        // list $1.36 / $4.18) is over; the model card and pricing page now show
        // $0.70 / $0.07 cached / $2.10 with no list price.
        $this->model('mistral-large-4', fn ($m) => $m
            ->displayName('Mistral Large 4')
            ->aliases('mistral-large-4-0')
            ->canGenerateText()
            ->tier('standard', inputPrice: 70, outputPrice: 210, cachedInputPrice: 7)
            ->tier('batch', inputPrice: 35, outputPrice: 105, cachedInputPrice: 3.5));

        $this->model('mistral-large-latest', fn ($m) => $m
            ->displayName('Mistral Large 3')
            ->aliases('mistral-large-2512')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150, cachedInputPrice: 5)
            ->tier('batch', inputPrice: 25, outputPrice: 75, cachedInputPrice: 2.5));

        $this->model('mistral-large-2411', fn ($m) => $m
            ->displayName('Mistral Large 2.1')
            ->retiredOn('2026-05-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600)
            ->tier('batch', inputPrice: 100, outputPrice: 300));

        $this->model('mistral-medium-latest', fn ($m) => $m
            ->displayName('Mistral Medium 3.5')
            ->aliases('mistral-medium-2604', 'mistral-medium-3-5', 'mistral-medium-3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15)
            ->tier('batch', inputPrice: 75, outputPrice: 375, cachedInputPrice: 7.5));

        $this->model('mistral-medium-2508', fn ($m) => $m
            ->displayName('Mistral Medium 3.1')
            ->retiredOn('2026-08-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200)
            ->tier('batch', inputPrice: 20, outputPrice: 100));

        $this->model('mistral-medium-2505', fn ($m) => $m
            ->displayName('Mistral Medium 3')
            ->retiredOn('2026-08-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200)
            ->tier('batch', inputPrice: 20, outputPrice: 100));

        $this->model('mistral-small-latest', fn ($m) => $m
            ->displayName('Mistral Small 4')
            ->aliases('mistral-small-2603')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60, cachedInputPrice: 1.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 30, cachedInputPrice: 0.75));

        $this->model('mistral-small-2506', fn ($m) => $m
            ->displayName('Mistral Small 3.2')
            ->retiredOn('2026-07-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30)
            ->tier('batch', inputPrice: 5, outputPrice: 15));

        $this->model('labs-mistral-small-creative', fn ($m) => $m
            ->displayName('Mistral Small Creative')
            ->aliases('mistral-small-creative')
            ->retiredOn('2026-04-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30)
            ->tier('batch', inputPrice: 5, outputPrice: 15));

        // The model card lists no price; the last known price is kept.
        $this->model('mistral-saba-latest', fn ($m) => $m
            ->displayName('Mistral Saba')
            ->aliases('mistral-saba-2502')
            ->retiredOn('2025-09-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 60));

        $this->model('ministral-3b-latest', fn ($m) => $m
            ->displayName('Ministral 3B')
            ->aliases('ministral-3b-2512')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10, cachedInputPrice: 1)
            ->tier('batch', inputPrice: 5, outputPrice: 5, cachedInputPrice: 0.5));

        $this->model('ministral-3b-2410', fn ($m) => $m
            ->displayName('Ministral 3B 24.10')
            ->retiredOn('2025-12-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 4, outputPrice: 4)
            ->tier('batch', inputPrice: 2, outputPrice: 2));

        $this->model('ministral-8b-latest', fn ($m) => $m
            ->displayName('Ministral 8B')
            ->aliases('ministral-8b-2512')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15, cachedInputPrice: 1.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 7.5, cachedInputPrice: 0.75));

        $this->model('ministral-8b-2410', fn ($m) => $m
            ->displayName('Ministral 8B 24.10')
            ->retiredOn('2025-12-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 10)
            ->tier('batch', inputPrice: 5, outputPrice: 5));

        $this->model('ministral-14b-latest', fn ($m) => $m
            ->displayName('Ministral 14B')
            ->aliases('ministral-14b-2512')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 20, cachedInputPrice: 2)
            ->tier('batch', inputPrice: 10, outputPrice: 10, cachedInputPrice: 1));

        // `mistral-nemo` is not a Mistral API id; it is kept as an alias so
        // older code keeps resolving to Mistral NeMo 12B.
        $this->model('open-mistral-nemo', fn ($m) => $m
            ->displayName('Mistral NeMo')
            ->aliases('open-mistral-nemo-2407', 'mistral-nemo')
            ->retiredOn('2026-07-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15)
            ->tier('batch', inputPrice: 7.5, outputPrice: 7.5));

        $this->model('pixtral-large-latest', fn ($m) => $m
            ->displayName('Pixtral Large')
            ->aliases('pixtral-large-2411')
            ->retiredOn('2026-05-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600)
            ->tier('batch', inputPrice: 100, outputPrice: 300));

        $this->model('pixtral-12b-2409', fn ($m) => $m
            ->displayName('Pixtral 12B')
            ->retiredOn('2025-12-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 15)
            ->tier('batch', inputPrice: 7.5, outputPrice: 7.5));

        // Magistral's thinking tokens are already part of completion_tokens.
        $this->model('magistral-medium-latest', fn ($m) => $m
            ->displayName('Magistral Medium')
            ->aliases('magistral-medium-2509', 'magistral-medium-2507', 'magistral-medium-2506')
            ->retiredOn('2026-07-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 500)
            ->tier('batch', inputPrice: 100, outputPrice: 250));

        $this->model('magistral-small-latest', fn ($m) => $m
            ->displayName('Magistral Small')
            ->aliases('magistral-small-2509', 'magistral-small-2507', 'magistral-small-2506')
            ->retiredOn('2026-07-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150)
            ->tier('batch', inputPrice: 25, outputPrice: 75));

        $this->model('codestral-latest', fn ($m) => $m
            ->displayName('Codestral')
            ->aliases('codestral-2508', 'codestral-2501')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 90, cachedInputPrice: 3)
            ->tier('batch', inputPrice: 15, outputPrice: 45, cachedInputPrice: 1.5));

        $this->model('devstral-medium-latest', fn ($m) => $m
            ->displayName('Devstral 2')
            ->aliases('devstral-latest', 'devstral-2512', 'devstral-medium-2507')
            ->retiredOn('2026-07-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 200)
            ->tier('batch', inputPrice: 20, outputPrice: 100));

        $this->model('devstral-small-latest', fn ($m) => $m
            ->displayName('Devstral Small')
            ->aliases('labs-devstral-small-2512', 'devstral-small-2507', 'devstral-small-2505')
            ->retiredOn('2026-03-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30)
            ->tier('batch', inputPrice: 5, outputPrice: 15));

        // Voxtral Small also bills $0.004 per minute of audio input, which a
        // token-priced entry cannot express.
        $this->model('voxtral-small-latest', fn ($m) => $m
            ->displayName('Voxtral Small')
            ->aliases('voxtral-small-2507')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40)
            ->tier('batch', inputPrice: 5, outputPrice: 20));

        // Third-party model hosted by Mistral.
        $this->model('zai-glm-5-3', fn ($m) => $m
            ->displayName('Z.ai GLM 5.3')
            ->aliases('zai-glm-5', 'zai-glm-latest')
            ->canGenerateText()
            ->tier('standard', inputPrice: 140, outputPrice: 440, cachedInputPrice: 14)
            ->tier('batch', inputPrice: 70, outputPrice: 220, cachedInputPrice: 7));

        $this->model('zai-glm-5-3-flash', fn ($m) => $m
            ->displayName('Z.ai GLM 5.3 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 50, cachedInputPrice: 3)
            ->tier('batch', inputPrice: 7.5, outputPrice: 25, cachedInputPrice: 1.5));

        $this->model('deepseek-v4-1-flash-1017', fn ($m) => $m
            ->displayName('DeepSeek V4.1 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 120, cachedInputPrice: 0.7)
            ->tier('batch', inputPrice: 15, outputPrice: 60, cachedInputPrice: 0.35));

        // Retires on 2026-10-31 (replaced by zai-glm-5-3).
        $this->model('zai-glm-5-2', fn ($m) => $m
            ->displayName('Z.ai GLM 5.2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 140, outputPrice: 440, cachedInputPrice: 14)
            ->tier('batch', inputPrice: 70, outputPrice: 220, cachedInputPrice: 7));

        // Prices kept from history; the model cards publish none.
        $this->model('open-mixtral-8x7b', fn ($m) => $m
            ->displayName('Mixtral 8x7B')
            ->retiredOn('2025-03-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 70, outputPrice: 70));

        $this->model('open-mixtral-8x22b', fn ($m) => $m
            ->displayName('Mixtral 8x22B')
            ->retiredOn('2025-03-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600));

        // Mistral Moderation 2 is free.
        $this->model('mistral-moderation-2603', fn ($m) => $m
            ->displayName('Mistral Moderation')
            ->canGenerateText()
            ->tier('standard', inputPrice: 0, outputPrice: 0));

        $this->model('mistral-moderation-2411', fn ($m) => $m
            ->displayName('Mistral Moderation 24.11')
            ->retiredOn('2026-06-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 0)
            ->tier('batch', inputPrice: 5, outputPrice: 0));

        $this->model('mistral-embed', fn ($m) => $m
            ->displayName('Mistral Embed')
            ->aliases('mistral-embed-2312')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0, cachedInputPrice: 1)
            ->tier('batch', inputPrice: 5, outputPrice: 0, cachedInputPrice: 0.5));

        $this->model('codestral-embed', fn ($m) => $m
            ->displayName('Codestral Embed')
            ->aliases('codestral-embed-2505')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0, cachedInputPrice: 1.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 0, cachedInputPrice: 0.75));

        $this->model('codestral-embed-latest', fn ($m) => $m
            ->displayName('Codestral Embed')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0, cachedInputPrice: 1.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 0, cachedInputPrice: 0.75));

        // OCR: per page ($4 / 1K pages for OCR 4.1). Pages with a document or
        // bounding-box annotation format cost $5 / 1K ($3 / 1K for OCR 3 and
        // OCR 2); that rate is not modelled, so annotated pages are under-counted.
        $this->model('mistral-ocr-latest', fn ($m) => $m
            ->displayName('Mistral OCR 4.1')
            ->aliases('mistral-ocr-4-1', 'mistral-ocr-4')
            ->type('text')
            ->pricingUnit('page')
            ->tier('standard', pricePerUnit: 0.4)
            ->tier('batch', pricePerUnit: 0.2));

        $this->model('mistral-ocr-4-0', fn ($m) => $m
            ->displayName('Mistral OCR 4.0')
            ->retiredOn('2026-09-30')
            ->type('text')
            ->pricingUnit('page')
            ->tier('standard', pricePerUnit: 0.4)
            ->tier('batch', pricePerUnit: 0.2));

        $this->model('mistral-ocr-2512', fn ($m) => $m
            ->displayName('Mistral OCR 3')
            ->type('text')
            ->pricingUnit('page')
            ->tier('standard', pricePerUnit: 0.2)
            ->tier('batch', pricePerUnit: 0.1));

        $this->model('mistral-ocr-2505', fn ($m) => $m
            ->displayName('Mistral OCR 2')
            ->retiredOn('2026-05-31')
            ->type('text')
            ->pricingUnit('page')
            ->tier('standard', pricePerUnit: 0.1)
            ->tier('batch', pricePerUnit: 0.05));

        $this->model('voxtral-mini-tts-latest', fn ($m) => $m
            ->displayName('Voxtral Mini TTS')
            ->aliases('voxtral-mini-tts-2603')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1600));

        $this->model('voxtral-mini-latest', fn ($m) => $m
            ->displayName('Voxtral Mini Transcribe')
            ->aliases('voxtral-mini-2602')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('voxtral-mini-realtime-latest', fn ($m) => $m
            ->displayName('Voxtral Mini Transcribe Realtime')
            ->aliases('voxtral-mini-transcribe-realtime-2602')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.6));
    }
}
