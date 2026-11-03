<?php

declare(strict_types=1);

use Spectra\Support\Pricing\Scraping\PriceChange;
use Spectra\Support\Pricing\Scraping\ScrapedPriceMapper;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * ScrapedPriceMapper turns Firecrawl's rows (currency units) into scraped prices
 * file entries (cents), only for models whose prices differ or are new.
 */
beforeEach(function () {
    $this->map = fn (array $rows) => (new ScrapedPriceMapper)->map(new FakeOpenAIPricing, $rows, '2026-10-31');
});

it('leaves out models whose prices match the catalog', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 1, 'output' => 4, 'cached_input' => 0.1],
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.3, 'output' => 1.2],
    ]);

    expect($result->models)->toBe([])
        ->and($result->changes)->toBe([])
        ->and($result->matchedModels)->toBe(2);
});

it('replaces the changed prices and keeps every other catalog row', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9, 'output' => 4],
    ]);

    expect($result->models['gpt-x']['tiers'])->toEqual([
        ['tier' => 'standard', 'input_price' => 90.0, 'output_price' => 400.0, 'cached_input_price' => 10.0],
        ['tier' => 'standard', 'input_price' => 200.0, 'output_price' => 600.0, 'above_input_tokens' => 200_000],
        ['tier' => 'batch', 'input_price' => 50.0, 'output_price' => 200.0],
    ])
        ->and($result->models['gpt-x']['aliases'])->toBe(['gpt-x-latest'])
        ->and($result->models['gpt-x']['display_name'])->toBe('GPT X')
        ->and($result->changes)->toHaveCount(1)
        ->and($result->changes[0])->toEqual(new PriceChange('gpt-x', 'standard', null, 'input_price', 100.0, 90.0, 'tokens'));
});

it('updates long-context rows by their threshold', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'above_input_tokens' => 200000, 'input' => 2.5],
    ]);

    expect($result->models['gpt-x']['tiers'][1])->toEqual(['tier' => 'standard', 'input_price' => 250.0, 'output_price' => 600.0, 'above_input_tokens' => 200_000])
        ->and($result->models['gpt-x']['tiers'][0]['input_price'])->toEqual(100.0);
});

it('adds a tier the model does not have yet', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'batch', 'input' => 0.15, 'output' => 0.6],
    ]);

    expect($result->models['gpt-y']['tiers'])->toEqual([
        ['tier' => 'standard', 'input_price' => 30.0, 'output_price' => 120.0],
        ['tier' => 'batch', 'input_price' => 15.0, 'output_price' => 60.0],
    ]);
});

it('does not add a token tier without both input and output prices', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'batch', 'input' => 0.15],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toContain('gpt-y: batch is missing input_price or output_price, not added.');
});

it('matches aliases, display names and case to catalog ids', function (string $id) {
    $result = ($this->map)([
        ['model_id' => $id, 'tier' => 'standard', 'input' => 0.9],
    ]);

    expect(array_keys($result->models))->toBe(['gpt-x'])
        ->and($result->newModels)->toBe([]);
})->with(['alias' => 'gpt-x-latest', 'display name' => 'GPT X', 'upper-case id' => 'GPT-X']);

it('matches by display name when the id is made up', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-x-turbo-preview', 'display_name' => 'GPT X', 'tier' => 'standard', 'input' => 0.9],
    ]);

    expect(array_keys($result->models))->toBe(['gpt-x']);
});

it('adds models the catalog does not have', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-z', 'display_name' => 'GPT Z', 'tier' => 'standard', 'unit' => 'tokens', 'input' => 2, 'output' => 8],
        ['model_id' => 'gpt-z', 'tier' => 'batch', 'input' => 1, 'output' => 4],
        ['model_id' => 'image-z', 'tier' => 'standard', 'unit' => 'image', 'price_per_unit' => 0.05],
    ]);

    expect($result->newModels)->toBe(['gpt-z', 'image-z'])
        ->and($result->models['gpt-z'])->toEqual([
            'display_name' => 'GPT Z',
            'type' => 'text',
            'pricing_unit' => 'tokens',
            'capabilities' => ['text'],
            'tiers' => [
                ['tier' => 'standard', 'input_price' => 200.0, 'output_price' => 800.0],
                ['tier' => 'batch', 'input_price' => 100.0, 'output_price' => 400.0],
            ],
        ])
        ->and($result->models['image-z'])->toEqual([
            'type' => 'image',
            'pricing_unit' => 'image',
            'capabilities' => ['images'],
            'tiers' => [['tier' => 'standard', 'input_price' => 0.0, 'output_price' => 0.0, 'price_per_unit' => 5.0]],
        ]);
});

it('does not add a new model without a standard price', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-z', 'tier' => 'batch', 'input' => 1, 'output' => 4],
    ]);

    expect($result->models)->toBe([])
        ->and($result->changes)->toBe([])
        ->and($result->skipped)->toContain('gpt-z: new model without a standard price, not added.');
});

it('skips ids that are not API ids', function () {
    $result = ($this->map)([
        ['model_id' => 'Some Brand New Model (beta)', 'tier' => 'standard', 'input' => 1, 'output' => 2],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe(["'Some Brand New Model (beta)' is not a model id."]);
});

it('keeps our price when the scraped one is off by an order of magnitude', function () {
    $result = ($this->map)([
        // A per-1K price read as per-1M.
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.001, 'output' => 3.5],
    ]);

    expect($result->models['gpt-x']['tiers'][0])->toEqual(['tier' => 'standard', 'input_price' => 100.0, 'output_price' => 350.0, 'cached_input_price' => 10.0])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0])->toContain('input_price for standard looks wrong');
});

it('updates per-unit models from the unit price only', function () {
    $result = ($this->map)([
        ['model_id' => 'image-x', 'tier' => 'hd', 'unit' => 'image', 'price_per_unit' => 0.12, 'input' => 5],
    ]);

    expect($result->models['image-x']['tiers'])->toEqual([
        ['tier' => 'standard', 'input_price' => 0.0, 'output_price' => 0.0, 'price_per_unit' => 4.0],
        ['tier' => 'hd', 'input_price' => 0.0, 'output_price' => 0.0, 'price_per_unit' => 12.0],
    ]);
});

it('skips a per-unit model priced in another unit', function () {
    $result = ($this->map)([
        ['model_id' => 'image-x', 'tier' => 'standard', 'unit' => 'megapixel', 'price_per_unit' => 0.03],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe(['image-x: the page prices it per megapixel, the catalog per image.']);
});

it('ignores a unit price for a token model', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'price_per_unit' => 0.5],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe(['gpt-y: the page has no token prices for standard.']);
});

it('keeps the first price when pages repeat a row', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.25],
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.35, 'output' => 1.5],
    ]);

    expect($result->models['gpt-y']['tiers'])->toEqual([
        ['tier' => 'standard', 'input_price' => 25.0, 'output_price' => 150.0],
    ]);
});

it('ignores negative and non-numeric prices', function () {
    $result = ($this->map)([
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => -1, 'output' => 'free'],
    ]);

    expect($result->models)->toBe([])
        ->and($result->skipped)->toBe([]);
});

it('updates the dated row in effect, not a future one', function () {
    $pricing = new class extends FakeOpenAIPricing
    {
        protected function define(): void
        {
            $this->model('gpt-d', fn ($m) => $m
                ->canGenerateText()
                ->cost(inputPrice: 100, outputPrice: 400)
                ->cost(inputPrice: 80, outputPrice: 320, since: '2026-06-01')
                ->cost(inputPrice: 60, outputPrice: 240, since: '2027-01-01'));
        }
    };

    $result = (new ScrapedPriceMapper)->map($pricing, [
        ['model_id' => 'gpt-d', 'tier' => 'standard', 'input' => 0.7],
    ], '2026-10-31');

    expect(array_column($result->models['gpt-d']['tiers'], 'input_price'))->toEqual([100.0, 70.0, 60.0]);
});

it('starts each map with no leftover changes', function () {
    $mapper = new ScrapedPriceMapper;
    $mapper->map(new FakeOpenAIPricing, [['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.4]]);

    $result = $mapper->map(new FakeOpenAIPricing, []);

    expect($result->changes)->toBe([])
        ->and($result->skipped)->toBe([]);
});
