<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingLookup;
use Workbench\App\Console\Commands\PricingDriftCommand;
use Workbench\App\Pricing\DriftFinding;
use Workbench\App\Pricing\ModelsDevDrift;

/**
 * spectra:pricing-drift (workbench, maintainers only) compares the catalog
 * with models.dev and reports what differs, minus what the baseline accepts.
 */
beforeEach(function () {
    $this->app->instance(PricingLookup::class, new PricingLookup([new class extends ProviderPricing
    {
        public function provider(): string
        {
            return 'openai';
        }

        protected function define(): void
        {
            $this->model('gpt-x', fn ($m) => $m
                ->canGenerateText()
                ->tier('standard', inputPrice: 100, outputPrice: 400, cachedInputPrice: 10)
                ->tier('standard', inputPrice: 200, outputPrice: 600, aboveInputTokens: 200_000));

            $this->model('gpt-x-image', fn ($m) => $m
                ->canGenerateImages()
                ->cost(inputPrice: 50, outputPrice: 300, imageOutputPrice: 3000));

            $this->model('gpt-x-tts', fn ($m) => $m
                ->pricingUnit(PricingUnit::Characters)
                ->cost(pricePerUnit: 1500));
        }
    }]));

    $this->catalog = [
        'openai' => ['models' => [
            'gpt-x' => ['cost' => [
                'input' => 1, 'output' => 4.5, 'cache_read' => 0.1, 'cache_write' => 0,
                'tiers' => [['input' => 3, 'output' => 6, 'cache_read' => 0.3, 'tier' => ['type' => 'context', 'size' => 200_000]]],
            ]],
            'gpt-x-2026-01-01' => ['cost' => ['input' => 1, 'output' => 4]],
            'gpt-x-image' => ['cost' => ['input' => 0.5, 'output' => 30]],
            'gpt-x-tts' => ['cost' => ['input' => 99, 'output' => 0]],
            'gpt-new' => ['cost' => ['input' => 2, 'output' => 8]],
            'gpt-old' => ['status' => 'deprecated', 'cost' => ['input' => 2, 'output' => 8]],
            'gpt-unpriced' => ['cost' => ['input' => 0, 'output' => 0]],
            'gpt-no-cost' => [],
        ]],
        'openrouter' => ['models' => [
            'vendor/not-in-catalog' => ['cost' => ['input' => 1, 'output' => 1]],
        ]],
    ];

    $this->source = tempnam(sys_get_temp_dir(), 'models-dev');
    $this->baseline = tempnam(sys_get_temp_dir(), 'drift-baseline');
    unlink($this->baseline);
    file_put_contents($this->source, json_encode($this->catalog));

    $this->app->make(Kernel::class)->registerCommand($this->app->make(PricingDriftCommand::class));
});

afterEach(function () {
    @unlink($this->source);
    @unlink($this->baseline);
});

it('reports differing prices and missing models, as Spectra bills them', function () {
    $findings = array_map(fn (DriftFinding $f) => [$f->kind, $f->model, $f->field, $f->spectra, $f->modelsDev],
        app(ModelsDevDrift::class)->compare($this->catalog));

    expect($findings)->toBe([
        ['price', 'gpt-x', 'output', 400.0, 450.0],
        ['price', 'gpt-x', 'input above 200000', 200.0, 300.0],
        // The long-context row has no cached price, so Spectra bills its input price.
        ['price', 'gpt-x', 'cached_input above 200000', 200.0, 30.0],
        ['missing', 'gpt-new', 'model', null, 200.0],
    ]);
});

it('fails with new findings and passes once they are in the baseline', function () {
    $options = ['--source' => $this->source, '--baseline' => $this->baseline];

    $this->artisan('spectra:pricing-drift', $options)
        ->expectsOutputToContain('4 new findings (3 differing prices, 1 missing models), 0 already in the baseline.')
        ->assertFailed();

    $this->artisan('spectra:pricing-drift', [...$options, '--update-baseline' => true])->assertSuccessful();

    expect(json_decode((string) file_get_contents($this->baseline), true))->toBe([
        'openai|gpt-new|model' => null,
        'openai|gpt-x|cached_input above 200000' => 30.0,
        'openai|gpt-x|input above 200000' => 300.0,
        'openai|gpt-x|output' => 450.0,
    ]);

    $this->artisan('spectra:pricing-drift', $options)
        ->expectsOutputToContain('0 new findings (0 differing prices, 0 missing models), 4 already in the baseline.')
        ->assertSuccessful();
});

it('reports a baselined price again when models.dev changes it', function () {
    file_put_contents($this->baseline, json_encode(['openai|gpt-x|output' => 450.0, 'openai|gone|model' => null]));
    $this->catalog['openai']['models']['gpt-x']['cost']['output'] = 5;
    file_put_contents($this->source, json_encode($this->catalog));

    $this->artisan('spectra:pricing-drift', ['--source' => $this->source, '--baseline' => $this->baseline])
        ->expectsOutputToContain('4 new findings')
        ->expectsOutputToContain('1 baseline entries no longer apply.')
        ->assertFailed();
});

it('writes a markdown report and downloads models.dev by default', function () {
    Http::fake([PricingDriftCommand::SOURCE => Http::response($this->catalog)]);
    $markdown = tempnam(sys_get_temp_dir(), 'drift-report');

    $this->artisan('spectra:pricing-drift', ['--baseline' => $this->baseline, '--markdown' => $markdown])->assertFailed();

    expect(file_get_contents($markdown))
        ->toContain('| openai | `gpt-x` | output | 400 | 450 |')
        ->toContain('| openai | `gpt-new` | 200 | 800 |');

    unlink($markdown);
});

it('fails when models.dev cannot be loaded', function () {
    Http::fake([PricingDriftCommand::SOURCE => Http::response('', 503)]);

    $this->artisan('spectra:pricing-drift', ['--baseline' => $this->baseline])
        ->expectsOutputToContain('Could not load models.dev')
        ->assertFailed();
});
