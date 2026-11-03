<?php

declare(strict_types=1);

use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\PricingLookup;

/**
 * PricingLookup answers price, tier, unit, display name, type and capability
 * questions for catalog models.
 */
it('returns the standard input and output price for an openai model', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o');

    expect($pricing)->not->toBeNull()
        ->and($pricing)->toHaveKeys(['input', 'output'])
        ->and($pricing['input'])->toBe(250)
        ->and($pricing['output'])->toBe(1000);
});

it('returns the standard input and output price for an anthropic model', function () {
    $pricing = app(PricingLookup::class)->get('anthropic', 'claude-sonnet-4-20250514');

    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(300)
        ->and($pricing['output'])->toBe(1500);
});

it('returns null for unknown models', function () {
    $pricing = app(PricingLookup::class)->get('unknown', 'unknown-model');

    expect($pricing)->toBeNull();
});

it('reports pricing for a known model and none for an unknown one', function () {
    $service = app(PricingLookup::class);

    expect($service->has('openai', 'gpt-4o'))->toBeTrue()
        ->and($service->has('unknown', 'model'))->toBeFalse();
});

it('returns cheaper batch and dearer priority prices for openai tiers', function () {
    $service = app(PricingLookup::class);

    // Standard tier (default)
    $standard = $service->get('openai', 'gpt-4o', PricingTier::Standard);
    expect($standard)->not->toBeNull()
        ->and($standard['input'])->toBe(250)
        ->and($standard['output'])->toBe(1000);

    // Batch tier (cheaper)
    $batch = $service->get('openai', 'gpt-4o', PricingTier::Batch);
    expect($batch)->not->toBeNull()
        ->and($batch['input'])->toBe(125)
        ->and($batch['output'])->toBe(500);

    // Priority tier (more expensive)
    $priority = $service->get('openai', 'gpt-4o', PricingTier::Priority);
    expect($priority)->not->toBeNull()
        ->and($priority['input'])->toBe(425)
        ->and($priority['output'])->toBe(1700);
});

it('resolves an openai tier passed as a string', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o', 'batch');

    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(125)
        ->and($pricing['output'])->toBe(500);
});

it('falls back to standard tier for unavailable tier-model combinations', function () {
    // gpt-4o has standard, batch, priority but NOT flex
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o', PricingTier::Flex);

    // Should fall back to standard tier
    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(250)
        ->and($pricing['output'])->toBe(1000);
});

it('has pricing for gpt-5 models', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-5.2', PricingTier::Standard);

    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(175)
        ->and($pricing['output'])->toBe(1400)
        ->and($pricing['cached_input'])->toBe(17.5);
});

it('has pricing for o3 and o4-mini models', function () {
    $service = app(PricingLookup::class);

    $o3 = $service->get('openai', 'o3', PricingTier::Standard);
    expect($o3)->not->toBeNull()
        ->and($o3['input'])->toBe(200)
        ->and($o3['output'])->toBe(800);

    $o4mini = $service->get('openai', 'o4-mini', PricingTier::Standard);
    expect($o4mini)->not->toBeNull()
        ->and($o4mini['input'])->toBe(110)
        ->and($o4mini['output'])->toBe(440);
});

it('halves anthropic prices, cached input included, on the batch tier', function () {
    $service = app(PricingLookup::class);

    // Standard tier
    $standard = $service->get('anthropic', 'claude-sonnet-4-20250514', 'standard');
    expect($standard)->not->toBeNull()
        ->and($standard['input'])->toBe(300)
        ->and($standard['output'])->toBe(1500)
        ->and($standard['cached_input'])->toBe(30);

    // Batch tier (50% discount)
    $batch = $service->get('anthropic', 'claude-sonnet-4-20250514', 'batch');
    expect($batch)->not->toBeNull()
        ->and($batch['input'])->toBe(150)
        ->and($batch['output'])->toBe(750)
        ->and($batch['cached_input'])->toBe(15);
});

it('has anthropic cache pricing for 5m and 1h writes', function () {
    $pricing = app(PricingLookup::class)->get('anthropic', 'claude-sonnet-4-20250514', 'standard');

    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(300)
        ->and($pricing['cached_input'])->toBe(30)
        ->and($pricing['cache_write_5m'])->toBe(375)
        ->and($pricing['cache_write_1h'])->toBe(600);
});

it('has pricing for claude opus 4.5', function () {
    $service = app(PricingLookup::class);

    $standard = $service->get('anthropic', 'claude-opus-4-5-20251101', 'standard');
    expect($standard)->not->toBeNull()
        ->and($standard['input'])->toBe(500)
        ->and($standard['output'])->toBe(2500);

    $batch = $service->get('anthropic', 'claude-opus-4-5-20251101', 'batch');
    expect($batch)->not->toBeNull()
        ->and($batch['input'])->toBe(250)
        ->and($batch['output'])->toBe(1250);
});

it('returns tokens as the pricing unit of a chat model', function () {
    $unit = app(PricingLookup::class)->getUnit('openai', 'gpt-4o');

    expect($unit)->toBe('tokens');
});

it('returns null unit for unknown model', function () {
    $unit = app(PricingLookup::class)->getUnit('unknown', 'unknown-model');

    expect($unit)->toBeNull();
});

it('returns the display name for a model id', function () {
    $name = app(PricingLookup::class)->getDisplayName('openai', 'gpt-4o');

    expect($name)->toBe('GPT-4o');
});

it('returns text as the model type of a chat model', function () {
    $type = app(PricingLookup::class)->getModelType('openai', 'gpt-4o');

    expect($type)->toBe('text');
});

it('reports only text generation for a text model', function () {
    $capabilities = app(PricingLookup::class)->getCapabilities('openai', 'gpt-4o');

    expect($capabilities)->toBe([
        'text' => true,
        'images' => false,
        'video' => false,
        'audio' => false,
    ]);
});

it('returns false capabilities for unknown model', function () {
    $capabilities = app(PricingLookup::class)->getCapabilities('unknown', 'unknown');

    expect($capabilities)->toBe([
        'text' => false,
        'images' => false,
        'video' => false,
        'audio' => false,
    ]);
});

it('lists chat models as text-generating and leaves out image models', function () {
    $textModels = app(PricingLookup::class)->canGenerateText();

    expect($textModels)->toContain('gpt-4o')
        ->and($textModels)->toContain('claude-sonnet-4')
        ->and($textModels)->not->toContain('dall-e-3');
});

it('lists image models as image-generating and leaves out chat models', function () {
    $imageModels = app(PricingLookup::class)->canGenerateImages();

    expect($imageModels)->toContain('dall-e-3')
        ->and($imageModels)->not->toContain('gpt-4o');
});

it('returns the full model data with its display name, type and tiers', function () {
    $data = app(PricingLookup::class)->getModelData('openai', 'gpt-4o');

    expect($data)->not->toBeNull()
        ->and($data)->toHaveKeys([
            'display_name', 'type', 'pricing_unit',
            'can_generate_text', 'can_generate_images',
            'can_generate_video', 'can_generate_audio', 'tiers',
        ])
        ->and($data['display_name'])->toBe('GPT-4o')
        ->and($data['type'])->toBe('text')
        ->and($data['tiers'])->toHaveKey('standard');
});

it('has pricing for scaleway models', function () {
    $service = app(PricingLookup::class);

    $llama = $service->get('scaleway', 'llama-3.3-70b-instruct', PricingTier::Standard);
    expect($llama)->not->toBeNull()
        ->and($llama['input'])->toBe(90)
        ->and($llama['output'])->toBe(90);

    $mistral = $service->get('scaleway', 'mistral-medium-3.5-128b', PricingTier::Standard);
    expect($mistral)->not->toBeNull()
        ->and($mistral['input'])->toBe(150)
        ->and($mistral['output'])->toBe(750);

    $embedding = $service->get('scaleway', 'bge-multilingual-gemma2', PricingTier::Standard);
    expect($embedding)->not->toBeNull()
        ->and($embedding['input'])->toBe(10)
        ->and($embedding['output'])->toBe(0);
});

it('reads pricing from all 7 providers', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->has('openai', 'gpt-4o'))->toBeTrue()
        ->and($lookup->has('anthropic', 'claude-sonnet-4-20250514'))->toBeTrue()
        ->and($lookup->has('google', 'gemini-2.5-pro'))->toBeTrue()
        ->and($lookup->has('xai', 'grok-3'))->toBeTrue()
        ->and($lookup->has('mistral', 'mistral-large-latest'))->toBeTrue()
        ->and($lookup->has('openrouter', 'moonshotai/kimi-k2.5'))->toBeTrue()
        ->and($lookup->has('replicate', 'black-forest-labs/flux-1.1-pro'))->toBeTrue();
});
