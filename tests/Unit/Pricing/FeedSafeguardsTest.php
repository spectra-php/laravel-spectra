<?php

declare(strict_types=1);

use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Feeds\FeedPriceMapper;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * The checks that keep a price feed's mistakes out of the pricing file: a
 * feed is not the provider, so a row that can't be right is skipped and
 * reported instead of saved.
 */
beforeEach(function () {
    $this->catalog = FakeCatalogs::acme();

    $this->map = fn (array $rows, ?ProviderPricing $catalog = null) => (new FeedPriceMapper)
        ->map($catalog ?? $this->catalog, $rows, '2026-11-01');
});

it('maps a tier label to its synonym in the catalog', function () {
    // OpenAI renamed Priority processing to "Fast"; the catalog keeps `priority`.
    $result = ($this->map)([
        ['model_id' => 'realtime-x', 'tier' => 'fast', 'input' => 9, 'output' => 48],
    ]);

    expect($result->changes[0]->tier)->toBe('priority')
        ->and($result->models['realtime-x']['tiers'][1]['input_price'])->toEqual(900.0);
});

it('ignores tiers and price fields the catalog does not use', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'ultrafast', 'input' => 1, 'output' => 4],
        // OpenAI's 12-hour cache write, read as the 1-hour write.
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.3, 'output' => 1.2, 'cache_write_1h' => 0.6],
    ], catalog: FakeCatalogs::openai());

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe([
            "Ignored 1 rows in the 'ultrafast' tier: the openai catalog doesn't use it.",
            "Ignored 1 cache write 1h prices: the openai catalog doesn't use them.",
        ]);
});

it('skips a row whose prices come from another column', function () {
    // The audio prices given as the text prices.
    $result = ($this->map)([
        ['model_id' => 'realtime-x', 'tier' => 'standard', 'input' => 32, 'output' => 64],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe(['realtime-x: standard prices look like they come from other columns (input 3200 is our audio input), kept ours.']);
});

it('skips rows with prices no provider charges', function (array $row, string $reason) {
    $result = ($this->map)([$row]);

    expect($result->models)->toBe([])
        ->and($result->skipped[0])->toContain($reason);
})->with([
    'output below input' => [['model_id' => 'long-x', 'tier' => 'standard', 'input' => 3, 'output' => 2.5], 'has output below input, kept ours'],
    'cached above input' => [['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2, 'output' => 12, 'cached_input' => 6], 'has cached input above input, kept ours'],
    'cheaper long context' => [['model_id' => 'long-x', 'tier' => 'standard', 'above_input_tokens' => 200000, 'input' => 1, 'output' => 15], 'is cheaper than the regular prices, kept ours'],
    'new model' => [['model_id' => 'new-x', 'tier' => 'standard', 'input' => 4, 'output' => 1], 'has output below input, not added'],
]);

it('converts prices with the quantity they are for', function () {
    // $13 per 1M characters is 1,300 cents per 1M characters.
    $result = ($this->map)([
        ['model_id' => 'tts-x', 'tier' => 'standard', 'unit' => 'characters', 'price_per_unit' => 13, 'priced_per' => 1000000],
    ]);

    expect($result->models['tts-x']['tiers'][0]['price_per_unit'])->toEqual(1300.0);
});

it('rejects a per-character price for a single character', function () {
    $result = ($this->map)([
        ['model_id' => 'mesh-x', 'tier' => 'standard', 'unit' => 'characters', 'price_per_unit' => 0.02, 'priced_per' => 1],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped[0])->toBe("mesh-x: a per-character price of 0.02 per 1 characters can't be right, ignored.");
});

it('adds a new model only when the provider has a handler for its kind', function () {
    // A feed lists fal.ai's speech and 3D models, but Spectra's fal handlers bill images and video only.
    $catalog = ProviderPricing::fromSection('falai', ['currency' => 'USD', 'models' => [
        'fal-ai/flux/dev' => ['type' => 'image', 'pricing_unit' => 'megapixel', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 2.5]]],
        'fal-ai/flux-pro/kontext' => ['type' => 'image', 'pricing_unit' => 'image', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 4]]],
    ]]);

    $result = ($this->map)([
        ['model_id' => 'fal-ai/z-image/turbo', 'tier' => 'standard', 'kind' => 'image', 'unit' => 'megapixel', 'price_per_unit' => 0.005],
        ['model_id' => 'fal-ai/ace-step/prompt-to-audio', 'tier' => 'standard', 'kind' => 'audio', 'unit' => 'second', 'price_per_unit' => 0.0002],
        ['model_id' => 'fal-ai/trellis', 'tier' => 'standard', 'kind' => '3d', 'unit' => 'image', 'price_per_unit' => 0.02],
    ], catalog: $catalog);

    expect($result->newModels)->toBe(['fal-ai/z-image/turbo'])
        ->and($result->skipped)->toBe([
            'fal-ai/ace-step/prompt-to-audio: new audio model, but no image/video handler bills it, not added.',
            'fal-ai/trellis: new 3d model, but no image/video handler bills it, not added.',
        ]);
});

it('adds a new per-unit model only when its price is on the scale of the catalog prices for that unit', function () {
    // Mistral prices OCR "$2 per 1,000 pages"; a row that gives it as $2 a page is off the scale.
    $catalog = ProviderPricing::fromSection('acme', ['currency' => 'USD', 'models' => [
        'ocr-latest' => ['pricing_unit' => 'page', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 0.4]]],
        'ocr-2512' => ['pricing_unit' => 'page', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 0.2]]],
    ]]);

    $result = ($this->map)([
        ['model_id' => 'ocr-3-25-12', 'tier' => 'standard', 'unit' => 'page', 'price_per_unit' => 2],
        ['model_id' => 'ocr-mini', 'tier' => 'standard', 'unit' => 'page', 'price_per_unit' => 0.001],
    ], catalog: $catalog);

    expect($result->newModels)->toBe(['ocr-mini'])
        ->and($result->skipped)->toBe(["ocr-3-25-12: per-page price 200 cents is far from the catalog's (0.2–0.4 cents), not added."]);
});

it('leaves models priced per option from their model pages to hand edits', function () {
    // A feed has Kling v3 at a flat $0.14 a second; the model page bills
    // $0.168 with audio and $0.112 without, as the variants say.
    $catalog = ProviderPricing::fromSection('falai', ['currency' => 'USD', 'models' => [
        'fal-ai/kling-video/v3/pro/text-to-video' => [
            'type' => 'video',
            'pricing_unit' => 'second',
            'variants' => ['*' => ['audio' => 16.8, 'silent' => 11.2]],
            'tiers' => [['tier' => 'standard', 'price_per_unit' => 16.8]],
        ],
    ]]);

    $result = ($this->map)([
        ['model_id' => 'fal-ai/kling-video/v3/pro/text-to-video', 'tier' => 'standard', 'kind' => 'video', 'unit' => 'second', 'price_per_unit' => 0.14],
        ['model_id' => 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video', 'tier' => 'standard', 'kind' => 'video', 'unit' => 'second', 'price_per_unit' => 0.07],
    ], catalog: $catalog);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe([
            'fal-ai/kling-video/v3/pro/text-to-video: priced per option from its model page (variants), kept ours. Edit it by hand.',
            'fal-ai/kling-video/v2.5-turbo/pro/image-to-video: the catalog prices its per-second models per option from their model pages (variants), not added. Add it by hand.',
        ]);
});
