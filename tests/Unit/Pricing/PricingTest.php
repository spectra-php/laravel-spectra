<?php

use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\PricingLookup;

it('should get pricing for openai models', function () {
    $pricing = app(PricingLookup::class)->get('openai', 'gpt-4o');

    expect($pricing)->not->toBeNull()
        ->and($pricing)->toHaveKeys(['input', 'output'])
        ->and($pricing['input'])->toBe(250)
        ->and($pricing['output'])->toBe(1000);
});

it('should get pricing for anthropic models', function () {
    $pricing = app(PricingLookup::class)->get('anthropic', 'claude-sonnet-4-20250514');

    expect($pricing)->not->toBeNull()
        ->and($pricing['input'])->toBe(300)
        ->and($pricing['output'])->toBe(1500);
});

it('returns null for unknown models', function () {
    $pricing = app(PricingLookup::class)->get('unknown', 'unknown-model');

    expect($pricing)->toBeNull();
});

it('should check if pricing exists', function () {
    $service = app(PricingLookup::class);

    expect($service->has('openai', 'gpt-4o'))->toBeTrue()
        ->and($service->has('unknown', 'model'))->toBeFalse();
});

it('should get openai pricing with different tiers', function () {
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

it('should get openai pricing with tier as string', function () {
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

it('should get anthropic pricing with different tiers', function () {
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

it('should get pricing unit for a model', function () {
    $unit = app(PricingLookup::class)->getUnit('openai', 'gpt-4o');

    expect($unit)->toBe('tokens');
});

it('returns null unit for unknown model', function () {
    $unit = app(PricingLookup::class)->getUnit('unknown', 'unknown-model');

    expect($unit)->toBeNull();
});

it('should get display name for a model', function () {
    $name = app(PricingLookup::class)->getDisplayName('openai', 'gpt-4o');

    expect($name)->toBe('GPT-4o');
});

it('should get model type', function () {
    $type = app(PricingLookup::class)->getModelType('openai', 'gpt-4o');

    expect($type)->toBe('text');
});

it('should get model capabilities', function () {
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

it('should get text-generating models', function () {
    $textModels = app(PricingLookup::class)->canGenerateText();

    expect($textModels)->toContain('gpt-4o')
        ->and($textModels)->toContain('claude-sonnet-4-20250514')
        ->and($textModels)->not->toContain('dall-e-3');
});

it('should get image-generating models', function () {
    $imageModels = app(PricingLookup::class)->canGenerateImages();

    expect($imageModels)->toContain('dall-e-3')
        ->and($imageModels)->not->toContain('gpt-4o');
});

it('should get full model data', function () {
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
