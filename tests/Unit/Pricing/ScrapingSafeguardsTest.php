<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Scraping\CatalogShape;
use Spectra\Support\Pricing\Scraping\PageText;
use Spectra\Support\Pricing\Scraping\PriceChange;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * The checks that keep LLM extraction mistakes out of the pricing file. Each
 * case comes from a mistake seen on a real pricing page.
 */
beforeEach(function () {
    $this->catalog = FakeCatalogs::acme();

    $this->map = fn (array $rows, ?string $page = null, ?ProviderPricing $catalog = null) => (new ScrapedPriceMapper)
        ->map($catalog ?? $this->catalog, $rows, '2026-11-01', $page !== null ? new PageText($page) : null);
});

it('maps a page tier label to its synonym in the catalog', function () {
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

it('limits the extraction schema to the catalog tiers, their synonyms and price fields', function () {
    $shape = CatalogShape::of(FakeCatalogs::openai());

    expect($shape->tierLabels())->toBe(['batch', 'standard', 'hd'])
        ->and($shape->fields)->toBe(['input_price', 'output_price', 'cached_input_price', 'price_per_unit'])
        ->and(CatalogShape::of($this->catalog)->tierLabels())->toBe(['standard', 'priority', 'fast']);
});

it('skips a row whose prices come from another column', function () {
    // "| realtime-x | Audio | $32.00 | $64.00 |" taken as the text prices.
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

it('keeps our price while the page still shows it next to the model', function () {
    // xAI prints the input image price before the output price on the same row.
    $page = '| imagine-x <br>Text, Image → Image | $0.01 / img | 1K · Low | $0.04 / img |';

    $result = ($this->map)([
        ['model_id' => 'imagine-x', 'tier' => 'standard', 'unit' => 'image', 'price_per_unit' => 0.01],
    ], $page);

    expect($result->models)->toBe([])
        ->and($result->skipped[0])->toContain('the page still shows our per unit price for standard next to the model, kept ours');
});

it('ignores prices the page does not print next to the model', function () {
    $page = implode("\n", [
        '| long-x | $2.50 | $12.00 |',
        '| other-model | $3.00 | $15.00 |',
    ]);

    $result = ($this->map)([
        ['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2.5, 'output' => 15],
    ], $page);

    expect($result->models['long-x']['tiers'][0])->toEqual(['tier' => 'standard', 'input_price' => 250.0, 'output_price' => 1200.0])
        ->and($result->skipped)->toBe(['long-x: standard output 15 not found next to the model on the page, ignored.']);
});

it('keeps the page line that confirms each change, for the review', function () {
    $page = implode("\n", [
        '## Long context',
        '| long-x | $2.50 | $12.00 |',
        '| long-x (> 200K) | $5.00 | $18.00 |',
        '| new-x | $1.00   |   $4.00 |',
    ]);

    $result = ($this->map)([
        ['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2.5, 'output' => 12],
        ['model_id' => 'new-x', 'tier' => 'standard', 'input' => 1, 'output' => 4],
    ], $page);

    $lines = collect($result->changes)->mapWithKeys(fn (PriceChange $change) => ["{$change->model} {$change->field}" => $change->pageLine])->all();

    expect($lines)->toBe([
        'long-x input_price' => '| long-x | $2.50 | $12.00 |',
        'new-x input_price' => '| new-x | $1.00 | $4.00 |',
        'new-x output_price' => '| new-x | $1.00 | $4.00 |',
    ]);
});

it('cuts a long confirming line for the review', function () {
    $page = '| long-x | $2.50 | $12.00 | '.Str::repeat('notes ', 50).'|';

    $change = ($this->map)([['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2.5]], $page)->changes[0];

    expect(Str::length((string) $change->pageLine))->toBe(ScrapedPriceMapper::MAX_PAGE_LINE_LENGTH + 1)
        ->and($change->pageLine)->toStartWith('| long-x | $2.50 | $12.00 |')->toEndWith('…');
});

it('converts printed prices with the quantity they are for', function () {
    $page = '| [Voxtral TTS](https://example.test/tts-x) | tts-x | $13 /M Chars |';

    $result = ($this->map)([
        ['model_id' => 'tts-x', 'tier' => 'standard', 'unit' => 'characters', 'price_per_unit' => 13, 'priced_per' => 1000000],
    ], $page);

    expect($result->models['tts-x']['tiers'][0]['price_per_unit'])->toEqual(1300.0);
});

it('rejects a quantity the page line contradicts', function () {
    $page = '| tts-x | $13 /M Chars |';

    $result = ($this->map)([
        ['model_id' => 'tts-x', 'tier' => 'standard', 'unit' => 'characters', 'price_per_unit' => 13, 'priced_per' => 1000],
    ], $page);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe(['tts-x: per unit 13 is priced per 1,000, but the page line says otherwise, ignored.']);
});

it('rejects a per-character price for a single character', function () {
    $result = ($this->map)([
        ['model_id' => 'mesh-x', 'tier' => 'standard', 'unit' => 'characters', 'price_per_unit' => 0.02, 'priced_per' => 1],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped[0])->toBe("mesh-x: a per-character price of 0.02 per 1 characters can't be right, ignored.");
});

it('matches a name whose words come in another order', function () {
    $result = ($this->map)([
        ['model_id' => 'claude-haiku-3-5', 'tier' => 'standard', 'input' => 1, 'output' => 5],
    ]);

    expect($result->newModels)->toBe([])
        ->and($result->matchedModels)->toBe(1);
});

it('matches a version without its v prefix', function () {
    $result = ($this->map)([
        ['model_id' => 'embed-5.0', 'tier' => 'standard', 'input' => 0.12],
    ]);

    expect(array_keys($result->models))->toBe(['embed-v5.0']);
});

it('adds a new model only when the page prints its id', function () {
    // ElevenLabs lists "Realtime v1 Mini" by name; the extraction made up `realtime_v1_mini`.
    $page = <<<'MD'
    ### Realtime v1 Mini
    $0.08 /min
    | `gpt-z` | $1.00 | $4.00 |
    MD;

    $result = ($this->map)([
        ['model_id' => 'realtime_v1_mini', 'display_name' => 'Realtime v1 Mini', 'tier' => 'standard', 'unit' => 'minute', 'price_per_unit' => 0.08],
        ['model_id' => 'gpt-z', 'tier' => 'standard', 'input' => 1, 'output' => 4],
    ], $page);

    expect($result->newModels)->toBe(['gpt-z'])
        ->and($result->skipped)->toContain("realtime_v1_mini: new model whose id the page doesn't print, not added.");
});

it('does not count an id inside a URL or a longer id', function () {
    $page = new PageText("| [OCR 4.1](https://docs.example.test/models/ocr-4-1) | \$4 |\n| fal-ai/trellis-2 | \$0.05 |");

    expect($page->mentions('ocr-4-1'))->toBeFalse()
        ->and($page->mentions('fal-ai/trellis'))->toBeFalse()
        ->and($page->mentions('fal-ai/trellis-2'))->toBeTrue();
});

it('adds a new model only when the provider has a handler for its kind', function () {
    // fal.ai lists speech and 3D models, but Spectra's fal handlers bill images and video only.
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
    // Mistral prices OCR "$2 per 1,000 pages"; the extraction read it as $2 a page.
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

it('rejects a token price the page prints per minute', function () {
    // Voxtral's audio input is "$0.0004 /Min"; the extraction took it as the cached token price.
    $page = '| [Voxtral Small](https://example.test/long-x) | long-x | $0.004 /Min | $0.0004 /Min | $12 /M Tokens |';

    $result = ($this->map)([
        ['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2, 'output' => 12, 'cached_input' => 0.0004],
    ], $page);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toContain('long-x: cached input 0.0004 is priced per min on the page, not per token, ignored.');
});

it('leaves models priced per option from their model pages to hand edits', function () {
    // fal.ai's pricing page shows Kling v3 at a flat $0.14 a second; the model
    // page bills $0.168 with audio and $0.112 without, as the variants say.
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

it('rejects a text price the page labels as another modality', function () {
    // Gemini prints "$2.00 (text) <br>$12.00 (audio)"; the extraction took $12 as the output price.
    $page = '| Output price (including thinking tokens) | Free of charge | $2.00 (text) <br>$12.00 (audio) | long-x |';

    $result = ($this->map)([
        ['model_id' => 'long-x', 'tier' => 'standard', 'input' => 2, 'output' => 12, 'audio_output' => 2],
    ], $page);

    expect($result->skipped)->toContain("long-x: output 12 is the page's audio price, ignored.")
        ->and($result->skipped)->toContain("long-x: audio output 2 is the page's text price, ignored.");
});

describe('PageText', function () {
    it('confirms a price on the table row naming the model', function () {
        $page = new PageText(implode("\n", [
            '| Model | Input | Output |',
            '| --- | --- | --- |',
            '| gpt-4.1-mini | $0.40 | $1.60 |',
            '| gpt-4.1 | $2.00 | $8.00 |',
            '| chat-latest | $5.00 | $30.00 |',
        ]));

        expect($page->confirms(['gpt-4.1'], 2))->toBeTrue()
            ->and($page->confirms(['gpt-4.1'], 8))->toBeTrue()
            // Another model's row, even right below.
            ->and($page->confirms(['gpt-4.1'], 5))->toBeFalse()
            // A longer id that starts with the name.
            ->and($page->confirms(['gpt-4.1'], 0.4))->toBeFalse();
    });

    it('confirms prices on continuation rows without a model of their own', function () {
        $page = new PageText(implode("\n", [
            '| gpt-realtime | Audio | $32.00 | $64.00 |',
            '| Text | $4.00 | $24.00 |',
            '| Image | $5.00 | - |',
            '| gpt-realtime-mini | Audio | $10.00 | $20.00 |',
            '| Text | $0.60 | $2.40 |',
        ]));

        expect($page->confirms(['gpt-realtime'], 4))->toBeTrue()
            ->and($page->confirms(['gpt-realtime'], 5))->toBeTrue()
            ->and($page->confirms(['gpt-realtime'], 0.6))->toBeFalse();
    });

    it('confirms prices in the lines below a heading', function () {
        $page = new PageText(implode("\n", [
            '## Gemini 3 Pro',
            '',
            '| | Free | Paid |',
            '| Input price | Free | $2.00, prompts <= 200k |',
        ]));

        expect($page->confirms(['Gemini 3 Pro'], 2))->toBeTrue()
            ->and($page->confirms(['Gemini 3 Pro'], 200))->toBeTrue();
    });

    it('does not count numbers in the model name or in prose', function () {
        $page = new PageText(implode("\n", [
            '| gpt-6-astra | $10.00 |',
            Str::repeat('Search content tokens for gpt-5 are billed as 8,000 input tokens per call, with a 5-minute minimum. ', 6),
        ]));

        expect($page->confirms(['gpt-6-astra'], 6))->toBeFalse()
            ->and($page->confirms(['gpt-5'], 5))->toBeFalse()
            ->and($page->confirms(['gpt-6-astra'], 10))->toBeTrue();
    });

    it('reads the quantity a line prices for', function (string $line, ?int $quantity) {
        expect(PageText::quantityOn($line))->toBe($quantity);
    })->with([
        ['| Claude Haiku 3.5 | $0.80/ MTok | $4/ MTok |', 1_000_000],
        ['| Voxtral TTS 3 | $13 /M Chars |', 1_000_000],
        ['$0.30 per 1K characters', 1000],
        ['$0.0025 / 1K tokens', 1000],
        ['| gpt-6-astra | $10.00 | $50.00 |', null],
        ['$0.04 / img', null],
    ]);
});
