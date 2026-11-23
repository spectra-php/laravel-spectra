<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Spectra\Support\Pricing\Feeds\LiteLlmFeed;
use Spectra\Support\Pricing\Feeds\ModelsDevPrices;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * spectra:pricing-sync reads LiteLLM's cost map and saves the prices that differ from the bundled catalog to the app's
 * pricing file: one feed id per catalog model, new models only when
 * models.dev agrees, and nothing that was declined. Written to the bundled
 * catalog itself, it changes that file's models in place.
 */
beforeEach(function () {
    $this->bundled = FakeCatalogs::useBundled([
        'openai' => [
            ...FakeCatalogs::openaiSection(),
            'models' => [
                ...FakeCatalogs::openaiSection()['models'],
                'whisper-x' => ['pricing_unit' => 'minute', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 0.36666666666666664]]],
                'tts-x' => ['pricing_unit' => 'characters', 'capabilities' => ['audio'], 'tiers' => [['tier' => 'standard', 'price_per_unit' => 1500]]],
            ],
        ],
        // The feed doesn't cover this provider.
        'scaleway' => FakeCatalogs::anthropicSection(),
    ]);
    $this->declined = storage_path('framework/testing/sync-declined-'.uniqid().'.json');
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');
    config(['spectra.costs.pricing_path' => $this->path]);

    $this->openai = fn (array $prices) => ['litellm_provider' => 'openai', 'mode' => 'chat', ...$prices];

    $this->feeds = fn (array $litellm = [], array $modelsDev = []) => Http::fake([
        LiteLlmFeed::SOURCE => Http::response($litellm),
        ModelsDevPrices::SOURCE => Http::response($modelsDev),
    ]);

    // Most tests update the bundled catalog, the way the catalog is maintained.
    $this->sync = fn (array $options = []) => $this->artisan('spectra:pricing-sync', ['--path' => $this->bundled, '--declined' => $this->declined, ...$options]);
    $this->models = fn (string $provider = 'openai') => json_decode(File::get($this->bundled), true, 512, JSON_THROW_ON_ERROR)['providers'][$provider]['models'];
});

afterEach(function () {
    File::delete([$this->bundled, $this->declined, $this->path]);
});

it('saves only the changed and new models to your pricing file', function () {
    ($this->feeds)(
        litellm: [
            'gpt-x' => ($this->openai)(['input_cost_per_token' => 0.9e-6, 'output_cost_per_token' => 4e-6]),
            'gpt-y' => ($this->openai)(['input_cost_per_token' => 0.3e-6, 'output_cost_per_token' => 1.2e-6]),
            'gpt-new' => ($this->openai)(['input_cost_per_token' => 2e-6, 'output_cost_per_token' => 8e-6]),
        ],
        modelsDev: ['openai' => ['models' => ['gpt-new' => ['name' => 'GPT New', 'cost' => ['input' => 2, 'output' => 8]]]]],
    );
    $bundled = File::get($this->bundled);

    $this->artisan('spectra:pricing-sync', ['--declined' => $this->declined])
        ->expectsOutputToContain('3 price changes, 1 new models, 0 declined')
        ->doesntExpectOutputToContain('SPECTRA_PRICING_PATH')
        ->assertSuccessful();

    $file = json_decode(File::get($this->path), true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($file['providers']))->toBe(['openai'])
        ->and($file['providers']['openai']['currency'])->toBe('USD')
        ->and(array_keys($file['providers']['openai']['models']))->toBe(['gpt-new', 'gpt-x'])
        // The whole model is saved, so its other rows keep billing.
        ->and($file['providers']['openai']['models']['gpt-x']['tiers'])->toHaveCount(3)
        ->and($file['providers']['openai']['models']['gpt-x']['tiers'][0]['input_price'])->toBe(90)
        ->and(File::get($this->bundled))->toBe($bundled);
});

it('drops a provider from your pricing file once the bundled catalog has its prices', function () {
    File::put($this->path, json_encode(['providers' => [
        'openai' => ['currency' => 'USD', 'models' => ['gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 90, 'output_price' => 400]]]]],
        'acme' => ['currency' => 'USD', 'models' => ['acme-1' => ['tiers' => [['tier' => 'standard', 'input_price' => 1, 'output_price' => 2]]]]],
    ]], JSON_THROW_ON_ERROR));
    ($this->feeds)(['gpt-x' => ($this->openai)(['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 4e-6])]);

    $this->artisan('spectra:pricing-sync', ['--declined' => $this->declined])->assertSuccessful();

    // A provider no feed covers is yours to keep.
    expect(array_keys(json_decode(File::get($this->path), true, 512, JSON_THROW_ON_ERROR)['providers']))->toBe(['acme']);
});

it('declines changes only for the bundled catalog', function () {
    ($this->feeds)(['gpt-x' => ($this->openai)(['input_cost_per_token' => 0.9e-6, 'output_cost_per_token' => 4e-6])]);

    $this->artisan('spectra:pricing-sync', ['--declined' => $this->declined, '--decline' => true])
        ->expectsOutputToContain('--decline records what the bundled catalog keeps as it is')
        ->assertFailed();

    expect(File::exists($this->declined))->toBeFalse();
});

it('writes the feed prices that differ to the bundled catalog and leaves the rest as it was', function () {
    ($this->feeds)([
        'gpt-x' => ($this->openai)([
            'input_cost_per_token' => 0.9e-6, 'output_cost_per_token' => 4e-6, 'cache_read_input_token_cost' => 0.1e-6,
            'input_cost_per_token_batches' => 0.45e-6, 'output_cost_per_token_batches' => 2e-6,
        ]),
        // Another host's price for the same model is not OpenAI's.
        'azure/gpt-x' => ['litellm_provider' => 'azure', 'mode' => 'chat', 'input_cost_per_token' => 5e-6, 'output_cost_per_token' => 9e-6],
    ]);
    $before = ($this->models)();

    ($this->sync)()
        ->expectsOutputToContain('2 price changes, 0 new models, 0 declined')
        ->expectsOutputToContain('Saved to')
        ->assertSuccessful();

    $models = ($this->models)();

    expect($models['gpt-x']['tiers'][0])->toBe(['tier' => 'standard', 'input_price' => 90, 'output_price' => 400, 'cached_input_price' => 10])
        ->and($models['gpt-x']['tiers'][2])->toBe(['tier' => 'batch', 'input_price' => 45, 'output_price' => 200])
        ->and($models['gpt-y'])->toBe($before['gpt-y'])
        ->and(($this->models)('scaleway'))->toHaveKey('claude-x');
});

it('adds a new model only when models.dev lists it at the same price', function () {
    ($this->feeds)(
        litellm: [
            'gpt-new' => ($this->openai)(['input_cost_per_token' => 2e-6, 'output_cost_per_token' => 8e-6]),
            'gpt-disputed' => ($this->openai)(['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 2e-6]),
            'gpt-unlisted' => ($this->openai)(['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 2e-6]),
            'gpt-retiring' => ($this->openai)(['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 2e-6]),
            // A dated snapshot of a model listed undated bills as that model.
            'gpt-new-2026-11-01' => ($this->openai)(['input_cost_per_token' => 2e-6, 'output_cost_per_token' => 8e-6]),
        ],
        modelsDev: ['openai' => ['models' => [
            'gpt-new' => ['name' => 'GPT New', 'cost' => ['input' => 2, 'output' => 8]],
            'gpt-new-2026-11-01' => ['name' => 'GPT New', 'cost' => ['input' => 2, 'output' => 8]],
            'gpt-disputed' => ['name' => 'GPT Disputed', 'cost' => ['input' => 3, 'output' => 6]],
            'gpt-retiring' => ['name' => 'GPT Retiring', 'status' => 'deprecated', 'cost' => ['input' => 1, 'output' => 2]],
        ]]],
    );

    ($this->sync)()
        ->expectsOutputToContain("Left out 3 feed models the catalog doesn't have")
        ->assertSuccessful();

    $models = ($this->models)();

    expect($models)->toHaveKey('gpt-new')
        ->not->toHaveKeys(['gpt-disputed', 'gpt-unlisted', 'gpt-retiring', 'gpt-new-2026-11-01'])
        ->and($models['gpt-new']['display_name'])->toBe('GPT New')
        ->and($models['gpt-new']['tiers'])->toBe([['tier' => 'standard', 'input_price' => 200, 'output_price' => 800]]);
});

it('adds no new models when models.dev cannot be loaded', function () {
    Sleep::fake();
    Http::fake([
        LiteLlmFeed::SOURCE => Http::response(['gpt-new' => ($this->openai)(['input_cost_per_token' => 2e-6, 'output_cost_per_token' => 8e-6])]),
        ModelsDevPrices::SOURCE => Http::response('', 503),
    ]);

    ($this->sync)()
        ->expectsOutputToContain('Could not load models.dev')
        ->expectsOutputToContain('The file already has every price the feed has.')
        ->assertSuccessful();

    expect(($this->models)())->not->toHaveKey('gpt-new');
});

it('bills a model at its own id and reports an alias the feed prices differently', function () {
    ($this->feeds)([
        'gpt-x-latest' => ($this->openai)(['input_cost_per_token' => 3e-6, 'output_cost_per_token' => 9e-6]),
        'gpt-x' => ($this->openai)(['input_cost_per_token' => 1.2e-6, 'output_cost_per_token' => 4e-6]),
        // An older snapshot at another price is not this entry's price.
        'gpt-x-2025-01-01' => ($this->openai)(['input_cost_per_token' => 7e-6, 'output_cost_per_token' => 9e-6]),
    ]);

    ($this->sync)()
        ->expectsOutputToContain('gpt-x: the feed prices gpt-x-latest at $3 / $9 and gpt-x at $1.2 / $4.')
        ->assertSuccessful();

    expect(($this->models)()['gpt-x']['tiers'][0]['input_price'])->toBe(120);
});

it('uses a dated snapshot when the feed lacks the model under its own id', function () {
    ($this->feeds)(['gpt-y-2026-01-01' => ($this->openai)(['input_cost_per_token' => 0.4e-6, 'output_cost_per_token' => 1.2e-6])]);

    ($this->sync)()->assertSuccessful();

    expect(($this->models)()['gpt-y']['tiers'][0]['input_price'])->toBe(40);
});

it('leaves a declined change alone until the feed changes that price', function () {
    $feed = fn (float $input) => ($this->feeds)(['gpt-x' => ($this->openai)([
        'input_cost_per_token' => $input, 'output_cost_per_token' => 4e-6,
        'input_cost_per_token_above_200k_tokens' => 2.5e-6, 'output_cost_per_token_above_200k_tokens' => 6e-6,
    ])]);
    $before = ($this->models)();

    $feed(0.9e-6);
    ($this->sync)(['--decline' => true])->expectsOutputToContain('Declined 2 changes')->assertSuccessful();

    expect(json_decode(File::get($this->declined), true, 512, JSON_THROW_ON_ERROR))->toBe([
        'openai|gpt-x|standard>200000|input_price' => 250.0,
        'openai|gpt-x|standard|input_price' => 90.0,
    ])->and(($this->models)())->toBe($before);

    ($this->sync)()
        ->expectsOutputToContain('0 price changes, 0 new models, 2 declined')
        ->expectsOutputToContain('The file already has every price the feed has.')
        ->assertSuccessful();

    Http::swap(new Factory);
    $feed(0.8e-6);
    ($this->sync)()->expectsOutputToContain('1 price changes, 0 new models, 1 declined')->assertSuccessful();

    expect(($this->models)()['gpt-x']['tiers'][0]['input_price'])->toBe(80)
        ->and(($this->models)()['gpt-x']['tiers'][1]['input_price'])->toBe(200);
});

it('converts a per-unit price to the catalog unit and ignores a rounded figure', function () {
    ($this->feeds)([
        // $0.0000611 a second is the catalog's 0.3667 cents a minute, rounded.
        'whisper-x' => ['litellm_provider' => 'openai', 'mode' => 'audio_transcription', 'input_cost_per_second' => 6.11e-05],
        // $0.000016 a character is 1,600 cents per 1M characters.
        'tts-x' => ['litellm_provider' => 'openai', 'mode' => 'audio_speech', 'input_cost_per_character' => 1.6e-05],
        // A per-unit model the catalog doesn't have is never added from the feed.
        'tts-new' => ['litellm_provider' => 'openai', 'mode' => 'audio_speech', 'input_cost_per_character' => 1.6e-05],
    ]);

    ($this->sync)()->expectsOutputToContain('1 price changes, 0 new models')->assertSuccessful();

    $models = ($this->models)();

    expect($models['tts-x']['tiers'][0]['price_per_unit'])->toBe(1600)
        ->and($models['whisper-x']['tiers'][0]['price_per_unit'])->toBe(0.36666666666666664)
        ->and($models)->not->toHaveKey('tts-new');
});

it('writes nothing on a dry run', function () {
    ($this->feeds)(['gpt-y' => ($this->openai)(['input_cost_per_token' => 0.4e-6, 'output_cost_per_token' => 1.2e-6])]);
    $before = File::get($this->bundled);

    ($this->sync)(['--dry-run' => true])
        ->expectsOutputToContain('1 price changes')
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(File::get($this->bundled))->toBe($before);
});

it('fails without writing when the feed cannot be loaded', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('', 503)]);
    $before = File::get($this->bundled);

    ($this->sync)()
        ->expectsOutputToContain('Could not load LiteLLM')
        ->expectsOutputToContain('The feed could not be loaded. Nothing was written.')
        ->assertFailed();

    expect(File::get($this->bundled))->toBe($before);
});

it('runs as spectra:pricing-scrape too, with the options that command had', function () {
    ($this->feeds)(['gpt-y' => ($this->openai)(['input_cost_per_token' => 0.4e-6, 'output_cost_per_token' => 1.2e-6])]);

    $this->artisan('spectra:pricing-scrape', ['provider' => ['openai'], '--all' => true, '--url' => ['https://example.test/pricing'], '--path' => $this->bundled, '--declined' => $this->declined])
        ->expectsOutputToContain('--url is ignored')
        ->expectsOutputToContain('1 price changes')
        ->assertSuccessful();

    expect(($this->models)()['gpt-y']['tiers'][0]['input_price'])->toBe(40);
});

it('refuses a provider the feed does not cover', function () {
    ($this->feeds)();

    ($this->sync)(['provider' => ['scaleway']])
        ->expectsOutputToContain("The price feed doesn't cover 'scaleway'")
        ->assertFailed();
});
