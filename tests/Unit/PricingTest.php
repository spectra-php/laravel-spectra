<?php

use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\PricingLookup;

it('can get pricing for openai models', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o');

    expect($pricing)->not->toBeNull();
    expect($pricing)->toHaveKeys(['input', 'output']);
    expect($pricing['input'])->toBe(250);
    expect($pricing['output'])->toBe(1000);
});

it('can get pricing for anthropic models', function () {
    $pricing = app(PricingLookup::class)->get('anthropic', 'claude-sonnet-4-20250514');

    expect($pricing)->not->toBeNull();
    expect($pricing['input'])->toBe(300);
    expect($pricing['output'])->toBe(1500);
});

it('returns null for unknown models', function () {
    $pricing = app(PricingLookup::class)->get('unknown', 'unknown-model');

    expect($pricing)->toBeNull();
});

it('can check if pricing exists', function () {
    $service = app(PricingLookup::class);

    expect($service->has('openai', 'gpt-4o'))->toBeTrue();
    expect($service->has('unknown', 'model'))->toBeFalse();
});

it('can get openai pricing with different tiers', function () {
    $service = app(PricingLookup::class);

    // Standard tier (default)
    $standard = $service->get('openai', 'gpt-4o', PricingTier::Standard);
    expect($standard)->not->toBeNull();
    expect($standard['input'])->toBe(250);
    expect($standard['output'])->toBe(1000);

    // Batch tier (cheaper)
    $batch = $service->get('openai', 'gpt-4o', PricingTier::Batch);
    expect($batch)->not->toBeNull();
    expect($batch['input'])->toBe(125);
    expect($batch['output'])->toBe(500);

    // Priority tier (more expensive)
    $priority = $service->get('openai', 'gpt-4o', PricingTier::Priority);
    expect($priority)->not->toBeNull();
    expect($priority['input'])->toBe(425);
    expect($priority['output'])->toBe(1700);
});

it('can get openai pricing with tier as string', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o', 'batch');

    expect($pricing)->not->toBeNull();
    expect($pricing['input'])->toBe(125);
    expect($pricing['output'])->toBe(500);
});

it('falls back to standard tier for unavailable tier-model combinations', function () {
    // gpt-4o has standard, batch, priority but NOT flex
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o', PricingTier::Flex);

    // Should fall back to standard tier
    expect($pricing)->not->toBeNull();
    expect($pricing['input'])->toBe(250);
    expect($pricing['output'])->toBe(1000);
});

it('has pricing for gpt-5 models', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-5.2', PricingTier::Standard);

    expect($pricing)->not->toBeNull();
    expect($pricing['input'])->toBe(175);
    expect($pricing['output'])->toBe(1400);
    expect($pricing['cached_input'])->toBe(17.5);
});

it('has pricing for o3 and o4-mini models', function () {
    $service = app(PricingLookup::class);

    $o3 = $service->get('openai', 'o3', PricingTier::Standard);
    expect($o3)->not->toBeNull();
    expect($o3['input'])->toBe(200);
    expect($o3['output'])->toBe(800);

    $o4mini = $service->get('openai', 'o4-mini', PricingTier::Standard);
    expect($o4mini)->not->toBeNull();
    expect($o4mini['input'])->toBe(110);
    expect($o4mini['output'])->toBe(440);
});

it('can get anthropic pricing with different tiers', function () {
    $service = app(PricingLookup::class);

    // Standard tier
    $standard = $service->get('anthropic', 'claude-sonnet-4-20250514', 'standard');
    expect($standard)->not->toBeNull();
    expect($standard['input'])->toBe(300);
    expect($standard['output'])->toBe(1500);
    expect($standard['cached_input'])->toBe(30);

    // Batch tier (50% discount)
    $batch = $service->get('anthropic', 'claude-sonnet-4-20250514', 'batch');
    expect($batch)->not->toBeNull();
    expect($batch['input'])->toBe(150);
    expect($batch['output'])->toBe(750);
    expect($batch['cached_input'])->toBe(15);
});

it('has anthropic cache pricing for 5m and 1h writes', function () {
    $pricing = app(PricingLookup::class)->get('anthropic', 'claude-sonnet-4-20250514', 'standard');

    expect($pricing)->not->toBeNull();
    expect($pricing['input'])->toBe(300);
    expect($pricing['cached_input'])->toBe(30);
    expect($pricing['cache_write_5m'])->toBe(375);
    expect($pricing['cache_write_1h'])->toBe(600);
});

it('has pricing for claude opus 4.5', function () {
    $service = app(PricingLookup::class);

    $standard = $service->get('anthropic', 'claude-opus-4-5-20251101', 'standard');
    expect($standard)->not->toBeNull();
    expect($standard['input'])->toBe(500);
    expect($standard['output'])->toBe(2500);

    $batch = $service->get('anthropic', 'claude-opus-4-5-20251101', 'batch');
    expect($batch)->not->toBeNull();
    expect($batch['input'])->toBe(250);
    expect($batch['output'])->toBe(1250);
});

it('can get pricing unit for a model', function () {
    $unit = app(PricingLookup::class)->getUnit('openai', 'gpt-4o');

    expect($unit)->toBe('tokens');
});

it('returns null unit for unknown model', function () {
    $unit = app(PricingLookup::class)->getUnit('unknown', 'unknown-model');

    expect($unit)->toBeNull();
});

it('can get display name for a model', function () {
    $name = app(PricingLookup::class)->getDisplayName('openai', 'gpt-4o');

    expect($name)->toBe('GPT-4o');
});

it('can get model type', function () {
    $type = app(PricingLookup::class)->getModelType('openai', 'gpt-4o');

    expect($type)->toBe('text');
});

it('can get model capabilities', function () {
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

it('can get text-generating models', function () {
    $textModels = app(PricingLookup::class)->canGenerateText();

    expect($textModels)->toContain('gpt-4o');
    expect($textModels)->toContain('claude-sonnet-4-20250514');
    expect($textModels)->not->toContain('dall-e-3');
});

it('can get image-generating models', function () {
    $imageModels = app(PricingLookup::class)->canGenerateImages();

    expect($imageModels)->toContain('dall-e-3');
    expect($imageModels)->not->toContain('gpt-4o');
});

it('can get full model data', function () {
    $data = app(PricingLookup::class)->getModelData('openai', 'gpt-4o');

    expect($data)->not->toBeNull();
    expect($data)->toHaveKeys([
        'display_name', 'type', 'pricing_unit',
        'can_generate_text', 'can_generate_images',
        'can_generate_video', 'can_generate_audio', 'tiers',
    ]);
    expect($data['display_name'])->toBe('GPT-4o');
    expect($data['type'])->toBe('text');
    expect($data['tiers'])->toHaveKey('standard');
});

it('reads pricing from all 7 providers', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->has('openai', 'gpt-4o'))->toBeTrue();
    expect($lookup->has('anthropic', 'claude-sonnet-4-20250514'))->toBeTrue();
    expect($lookup->has('google', 'gemini-2.5-pro'))->toBeTrue();
    expect($lookup->has('xai', 'grok-3'))->toBeTrue();
    expect($lookup->has('mistral', 'mistral-large-latest'))->toBeTrue();
    expect($lookup->has('openrouter', 'moonshotai/kimi-k2.5'))->toBeTrue();
    expect($lookup->has('replicate', 'black-forest-labs/flux-1.1-pro'))->toBeTrue();
});
