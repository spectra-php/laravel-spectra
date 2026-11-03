<?php

declare(strict_types=1);

use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Scraping\CatalogShape;
use Spectra\Support\Pricing\Scraping\PageText;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * The checks that keep LLM extraction mistakes out of the pricing file. Each
 * case comes from a mistake seen on a real pricing page.
 */
beforeEach(function () {
    $this->catalog = new class extends ProviderPricing
    {
        public function provider(): string
        {
            return 'acme';
        }

        protected function define(): void
        {
            $this->model('realtime-x', fn ($m) => $m
                ->canGenerateText()
                ->tier('standard', inputPrice: 400, outputPrice: 2400, cachedInputPrice: 40, audioInputPrice: 3200, audioOutputPrice: 6400)
                ->tier('priority', inputPrice: 800, outputPrice: 4800, cachedInputPrice: 80, audioInputPrice: 6400, audioOutputPrice: 12800));

            $this->model('long-x', fn ($m) => $m
                ->canGenerateText()
                ->tier('standard', inputPrice: 200, outputPrice: 1200)
                ->tier('standard', inputPrice: 400, outputPrice: 1800, aboveInputTokens: 200_000));

            $this->model('claude-3-5-haiku', fn ($m) => $m
                ->displayName('Claude 3.5 Haiku')
                ->canGenerateText()
                ->cost(inputPrice: 80, outputPrice: 400));

            $this->model('embed-v5.0', fn ($m) => $m
                ->type('embedding')
                ->cost(inputPrice: 10));

            $this->model('imagine-x', fn ($m) => $m
                ->type('image')
                ->pricingUnit('image')
                ->canGenerateImages()
                ->cost(pricePerUnit: 4));

            $this->model('tts-x', fn ($m) => $m
                ->pricingUnit('characters')
                ->canGenerateAudio()
                ->cost(pricePerUnit: 1500));
        }
    };

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
    ], catalog: new FakeOpenAIPricing);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe([
            "Ignored 1 rows in the 'ultrafast' tier: the openai catalog doesn't use it.",
            "Ignored 1 cache write 1h prices: the openai catalog doesn't use them.",
        ]);
});

it('limits the extraction schema to the catalog tiers, their synonyms and price fields', function () {
    $shape = CatalogShape::of(new FakeOpenAIPricing);

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
            str_repeat('Search content tokens for gpt-5 are billed as 8,000 input tokens per call, with a 5-minute minimum. ', 6),
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
