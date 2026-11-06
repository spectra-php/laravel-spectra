<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Support\Pricing\PricingCatalog;

/**
 * Guardrails over the bundled pricing catalog. These prevent the class of
 * bug where a model silently bills $0 — e.g. an unsupported pricing unit, or
 * a per-unit model that lacks the `standard` tier the cost calculator falls
 * back to, a tier name that silently bills at standard, or fal.ai video
 * prices that drift from their variants. Every bundled provider/model is
 * checked.
 */

/** @return array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: array<int, array<string, mixed>>}> */
function spectraCatalogRows(): array
{
    $rows = [];

    foreach (PricingCatalog::bundled() as $provider => $pricing) {
        foreach ($pricing->models() as $definition) {
            $rows[] = [
                $provider,
                $definition->getInternalName(),
                $definition->toModelArray(),
                $definition->toTiersArray(),
            ];
        }
    }

    return $rows;
}

it('bundles a catalog for every built-in provider', function () {
    expect(array_keys(PricingCatalog::bundled()))->toBe([
        'anthropic', 'cohere', 'elevenlabs', 'falai', 'google', 'groq', 'mistral', 'openai', 'openrouter', 'replicate', 'scaleway', 'xai',
    ]);
});

it('lists pricing pages for every provider but OpenRouter, which reports its costs', function () {
    foreach (PricingCatalog::bundled() as $provider => $pricing) {
        if ($provider === 'openrouter') {
            expect($pricing->sources())->toBe([]);

            continue;
        }

        expect($pricing->sources())->not->toBeEmpty("{$provider} has no pricing pages to scrape");
    }
});

it('prices the separately billed tool calls of OpenAI, Anthropic and xAI', function () {
    $bundled = PricingCatalog::bundled();

    expect($bundled['openai']->toolCallPricing())->toHaveKeys(['web_search_call', 'file_search_call', 'code_interpreter_call'])
        ->and($bundled['anthropic']->toolCallPricing())->toHaveKey('web_search_requests')
        ->and($bundled['xai']->toolCallPricing())->toHaveKey('x_search_post');
});

it('defines a non-empty catalog for every bundled provider', function () {
    expect(spectraCatalogRows())->not->toBeEmpty();
});

it('uses only supported pricing units', function () {
    $valid = Arr::map(PricingUnit::cases(), fn (PricingUnit $u) => $u->value);

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        expect($model['pricing_unit'])->toBeIn($valid, "{$provider}/{$name} uses unsupported pricing unit '{$model['pricing_unit']}'");
    }
});

it('gives every model a standard tier', function () {
    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        expect('standard')->toBeIn(Arr::pluck($tiers, 'tier'), "{$provider}/{$name} has no 'standard' tier (cost calculation falls back to standard)");
    }
});

it('gives per-unit models a billable price on the standard tier', function () {
    $perUnit = [
        PricingUnit::Image->value,
        PricingUnit::Video->value,
        PricingUnit::Minute->value,
        PricingUnit::Second->value,
        PricingUnit::Characters->value,
        PricingUnit::Search->value,
    ];

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        if (! in_array($model['pricing_unit'], $perUnit, true)) {
            continue;
        }

        $standard = collect($tiers)->firstWhere('tier', 'standard');

        expect($standard['price_per_unit'] ?? null)->not->toBeNull("{$provider}/{$name} is priced per {$model['pricing_unit']} but its standard tier has no price_per_unit (would always bill 0)");
    }
});

it('gives token-based models numeric input/output prices on the standard tier', function () {
    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        if ($model['pricing_unit'] !== PricingUnit::Tokens->value) {
            continue;
        }

        $standard = collect($tiers)->firstWhere('tier', 'standard');

        expect($standard['input_price'])->toBeNumeric("{$provider}/{$name} standard tier input_price")
            ->and($standard['output_price'])->toBeNumeric("{$provider}/{$name} standard tier output_price");
    }
});

it('names every tier with a PricingTier value', function () {
    $valid = Arr::map(PricingTier::cases(), fn (PricingTier $tier) => $tier->value);

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        foreach (collect($tiers)->pluck('tier')->unique() as $tier) {
            expect($tier)->toBeIn($valid, "{$provider}/{$name} uses unknown tier '{$tier}' (it would bill at standard)");
        }
    }
});

it('uses only catalog types the model type resolution knows', function () {
    $valid = ['text', 'embedding', 'image', 'audio', 'video'];

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        expect($model['type'])->toBeIn($valid, "{$provider}/{$name} uses unknown type '{$model['type']}'");
    }
});

it('never lists the same model id or alias twice within a provider', function () {
    $seen = [];

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        foreach ([$name, ...$model['aliases']] as $id) {
            expect($seen)->not->toHaveKey("{$provider}|{$id}", message: "{$provider}/{$id} is listed by both {$name} and ".($seen["{$provider}|{$id}"] ?? ''));

            $seen["{$provider}|{$id}"] = $name;
        }
    }
});

it('prices every fal.ai video model with variants at its first resolution with audio', function () {
    $checked = 0;

    foreach (PricingCatalog::bundled()['falai']->models() as $definition) {
        $variants = $definition->getVariants();

        if ($variants === []) {
            continue;
        }

        $model = $definition->getInternalName();
        $standard = collect($definition->toTiersArray())->firstWhere('tier', PricingTier::Standard->value);

        expect($definition->toModelArray()['pricing_unit'])->toBe(PricingUnit::Second->value, $model)
            ->and($standard['price_per_unit'])->toEqual(Arr::first($variants)['audio'], $model);

        foreach ($variants as $resolution => $prices) {
            expect($prices['audio'])->toBeGreaterThan(0, "{$model} {$resolution} with audio")
                ->and($prices['silent'])->toBeGreaterThan(0, "{$model} {$resolution} without audio");
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('lists variants for every per-second fal.ai video model', function () {
    foreach (PricingCatalog::bundled()['falai']->models() as $definition) {
        $model = $definition->toModelArray();

        if ($model['type'] !== 'video' || $model['pricing_unit'] !== PricingUnit::Second->value) {
            continue;
        }

        expect($definition->getVariants())->not->toBeEmpty("{$definition->getInternalName()} has no resolution / audio prices");
    }
});
