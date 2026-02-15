<?php

use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\ModelDefinition;

it('can build a model definition with all fields', function () {
    $model = new ModelDefinition('gpt-4o');
    $model->displayName('GPT-4o')
        ->type('text')
        ->pricingUnit('tokens')
        ->canGenerateText();

    $array = $model->toModelArray();

    expect($array)->toBe([
        'internal_name' => 'gpt-4o',
        'display_name' => 'GPT-4o',
        'type' => 'text',
        'pricing_unit' => 'tokens',
        'can_generate_text' => true,
        'can_generate_images' => false,
        'can_generate_video' => false,
        'can_generate_audio' => false,
    ]);
});

it('can build a model with multiple capabilities', function () {
    $model = new ModelDefinition('multimodal');
    $model->canGenerateText()
        ->canGenerateImages()
        ->canGenerateAudio();

    $array = $model->toModelArray();

    expect($array['can_generate_text'])->toBeTrue();
    expect($array['can_generate_images'])->toBeTrue();
    expect($array['can_generate_audio'])->toBeTrue();
    expect($array['can_generate_video'])->toBeFalse();
});

it('can build a model with pricing tiers', function () {
    $model = new ModelDefinition('gpt-4o');
    $model->tier('standard', inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125)
        ->tier('batch', inputPrice: 125, outputPrice: 500);

    $tiers = $model->toTiersArray();

    expect($tiers)->toHaveCount(2);

    expect($tiers[0])->toBe([
        'tier' => 'standard',
        'input_price' => 250.0,
        'output_price' => 1000.0,
        'cached_input_price' => 125.0,
        'cache_write_5m_price' => null,
        'cache_write_1h_price' => null,
        'price_per_unit' => null,
    ]);

    expect($tiers[1])->toBe([
        'tier' => 'batch',
        'input_price' => 125.0,
        'output_price' => 500.0,
        'cached_input_price' => null,
        'cache_write_5m_price' => null,
        'cache_write_1h_price' => null,
        'price_per_unit' => null,
    ]);
});

it('can build a model with cache write pricing', function () {
    $model = new ModelDefinition('claude-sonnet');
    $model->tier('standard',
        inputPrice: 300,
        outputPrice: 1500,
        cachedInputPrice: 30,
        cacheWrite5mPrice: 375,
        cacheWrite1hPrice: 600
    );

    $tiers = $model->toTiersArray();

    expect($tiers[0]['cache_write_5m_price'])->toBe(375.0);
    expect($tiers[0]['cache_write_1h_price'])->toBe(600.0);
});

it('can build a unit-based model with price per unit', function () {
    $model = new ModelDefinition('dall-e-3');
    $model->displayName('DALL-E 3')
        ->type('image')
        ->pricingUnit('image')
        ->canGenerateImages()
        ->tier('standard', pricePerUnit: 4000);

    $array = $model->toModelArray();
    $tiers = $model->toTiersArray();

    expect($array['type'])->toBe('image');
    expect($array['pricing_unit'])->toBe('image');
    expect($array['can_generate_images'])->toBeTrue();
    expect($tiers[0]['price_per_unit'])->toBe(4000.0);
    expect($tiers[0]['input_price'])->toBe(0.0);
    expect($tiers[0]['output_price'])->toBe(0.0);
});

it('can build a model with cost shorthand for standard tier', function () {
    $model = new ModelDefinition('gpt-4o-mini');
    $model->displayName('GPT-4o Mini')
        ->canGenerateText()
        ->cost(inputPrice: 15, outputPrice: 60, cachedInputPrice: 7.5);

    $tiers = $model->toTiersArray();

    expect($tiers)->toHaveCount(1);

    expect($tiers[0])->toBe([
        'tier' => 'standard',
        'input_price' => 15.0,
        'output_price' => 60.0,
        'cached_input_price' => 7.5,
        'cache_write_5m_price' => null,
        'cache_write_1h_price' => null,
        'price_per_unit' => null,
    ]);
});

it('can combine cost shorthand with additional tiers', function () {
    $model = new ModelDefinition('gpt-4o');
    $model->cost(inputPrice: 250, outputPrice: 1000)
        ->tier('batch', inputPrice: 125, outputPrice: 500);

    $tiers = $model->toTiersArray();

    expect($tiers)->toHaveCount(2);
    expect($tiers[0]['tier'])->toBe('standard');
    expect($tiers[1]['tier'])->toBe('batch');
});

it('can use cost shorthand with price per unit', function () {
    $model = new ModelDefinition('dall-e-3');
    $model->type('image')
        ->pricingUnit('image')
        ->canGenerateImages()
        ->cost(pricePerUnit: 4000);

    $tiers = $model->toTiersArray();

    expect($tiers)->toHaveCount(1);
    expect($tiers[0]['tier'])->toBe('standard');
    expect($tiers[0]['price_per_unit'])->toBe(4000.0);
});

it('uses default values for type and pricing unit', function () {
    $model = new ModelDefinition('basic-model');

    $array = $model->toModelArray();

    expect($array['type'])->toBe('text');
    expect($array['pricing_unit'])->toBe('tokens');
});

// ProviderPricing tests

it('provider pricing returns models from define method', function () {
    $pricing = new class extends ProviderPricing
    {
        public function provider(): string
        {
            return 'test';
        }

        protected function define(): void
        {
            $this->model('test-model', fn ($m) => $m
                ->displayName('Test Model')
                ->canGenerateText()
                ->cost(inputPrice: 100, outputPrice: 200));
        }
    };

    $models = $pricing->models();

    expect($models)->toHaveCount(1);
    expect($models[0])->toBeInstanceOf(ModelDefinition::class);
    expect($models[0]->getInternalName())->toBe('test-model');
});

it('provider pricing merges define and populate models', function () {
    $pricing = new class extends ProviderPricing
    {
        public function provider(): string
        {
            return 'test';
        }

        protected function define(): void
        {
            $this->model('built-in-model', fn ($m) => $m
                ->canGenerateText()
                ->cost(inputPrice: 100, outputPrice: 200));
        }

        protected function populate(): void
        {
            $this->model('custom-model', fn ($m) => $m
                ->canGenerateText()
                ->cost(inputPrice: 50, outputPrice: 100));
        }
    };

    $models = $pricing->models();

    expect($models)->toHaveCount(2);

    $names = array_map(fn ($m) => $m->getInternalName(), $models);
    expect($names)->toBe(['built-in-model', 'custom-model']);
});

it('provider pricing toArray returns structured data', function () {
    $pricing = new class extends ProviderPricing
    {
        public function provider(): string
        {
            return 'my-provider';
        }

        protected function define(): void
        {
            $this->model('model-a', fn ($m) => $m
                ->displayName('Model A')
                ->canGenerateText()
                ->cost(inputPrice: 100, outputPrice: 200));
        }
    };

    $array = $pricing->toArray();

    expect($array)->toHaveKey('provider', 'my-provider');
    expect($array)->toHaveKey('models');
    expect($array['models'])->toHaveCount(1);
    expect($array['models'][0])->toHaveKey('model');
    expect($array['models'][0])->toHaveKey('tiers');
});
