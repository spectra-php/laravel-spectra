<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Pricing\ProviderPricing;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * ProviderPricing is one provider's section of a pricing file: its currency,
 * pricing pages, tool call prices and models, in the file's order.
 */
it('reads a provider section', function () {
    $pricing = ProviderPricing::fromSection('openai', [
        ...FakeCatalogs::openaiSection(),
        'tool_calls' => ['web_search_call' => 1, 'file_search_call' => '0.25'],
    ]);

    expect($pricing->provider())->toBe('openai')
        ->and($pricing->currency())->toBe('USD')
        ->and($pricing->sources())->toBe(['https://example.test/pricing'])
        ->and($pricing->toolCallPricing())->toBe(['web_search_call' => 1.0, 'file_search_call' => 0.25])
        ->and(Arr::map($pricing->models(), fn ($m) => $m->getInternalName()))->toBe(['gpt-x', 'gpt-y', 'image-x']);
});

it('defaults the currency to the bundled catalog it sits on, else USD', function () {
    $bundled = new ProviderPricing('scaleway', [], 'EUR');

    expect(ProviderPricing::fromSection('scaleway', ['models' => []], $bundled)->currency())->toBe('EUR')
        ->and(ProviderPricing::fromSection('scaleway', ['currency' => 'usd', 'models' => []], $bundled)->currency())->toBe('USD')
        ->and(ProviderPricing::fromSection('acme', ['models' => []])->currency())->toBe('USD');
});

it('skips a tool call price that is not a number and warns', function () {
    Log::spy();

    $pricing = ProviderPricing::fromSection('acme', ['tool_calls' => ['web_search_call' => 'free', 'code_interpreter_call' => 3], 'models' => []], origin: 'prices.json');

    expect($pricing->toolCallPricing())->toBe(['code_interpreter_call' => 3.0]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, ["'web_search_call'", 'prices.json']));
});

it('keeps a model\'s variants', function () {
    $pricing = new ProviderPricing('falai', [
        'fal-ai/veo' => ['type' => 'video', 'pricing_unit' => 'second', 'variants' => ['720p' => ['audio' => 40, 'silent' => 20]], 'tiers' => [['tier' => 'standard', 'price_per_unit' => 40]]],
    ]);

    expect($pricing->models()[0]->getVariants())->toBe(['720p' => ['audio' => 40, 'silent' => 20]])
        ->and(ProviderPricing::definitionToArray($pricing->models()[0])['variants'])->toBe(['720p' => ['audio' => 40, 'silent' => 20]]);
});

it('exports its section with every model it could parse', function () {
    $section = [
        'currency' => 'USD',
        'sources' => ['https://example.test/pricing'],
        'tool_calls' => ['web_search_call' => 1.0],
        'models' => [
            'gpt-x' => ['display_name' => 'GPT X', 'type' => 'text', 'pricing_unit' => 'tokens', 'capabilities' => ['text'], 'tiers' => [['tier' => 'standard', 'input_price' => 100.0, 'output_price' => 400.0]]],
            'broken' => ['tiers' => []],
        ],
    ];

    $exported = ProviderPricing::fromSection('openai', $section)->toSection();

    expect($exported)->toEqual([...$section, 'models' => Arr::except($section['models'], 'broken')])
        ->and(array_keys($exported))->toBe(['currency', 'sources', 'tool_calls', 'models']);
});

it('leaves empty pages and tool call prices out of its section', function () {
    expect(array_keys(FakeCatalogs::acme()->toSection()))->toBe(['currency', 'models']);
});
