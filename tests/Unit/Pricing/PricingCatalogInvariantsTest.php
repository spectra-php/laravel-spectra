<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;

/**
 * Guardrails over the entire built-in pricing catalog. These prevent the class
 * of bug where a model silently bills $0 — e.g. an unsupported pricing unit, or
 * a per-unit model that lacks the `standard` tier the cost calculator falls back
 * to. Every configured provider/model is checked.
 */

/** @return array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: array<int, array<string, mixed>>}> */
function spectraCatalogRows(): array
{
    $rows = [];

    foreach (config('spectra.costs.pricing', []) as $class) {
        /** @var ProviderPricing $pricing */
        $pricing = app($class);
        $provider = $pricing->provider();

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

it('defines a non-empty catalog for every configured provider', function () {
    expect(spectraCatalogRows())->not->toBeEmpty();
});

it('uses only supported pricing units', function () {
    $valid = array_map(fn (PricingUnit $u) => $u->value, PricingUnit::cases());

    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        Assert::assertContains(
            $model['pricing_unit'],
            $valid,
            "{$provider}/{$name} uses unsupported pricing unit '{$model['pricing_unit']}'"
        );
    }
});

it('gives every model a standard tier', function () {
    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        Assert::assertContains(
            'standard',
            array_column($tiers, 'tier'),
            "{$provider}/{$name} has no 'standard' tier (cost calculation falls back to standard)"
        );
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

        Assert::assertNotNull(
            $standard['price_per_unit'] ?? null,
            "{$provider}/{$name} is priced per {$model['pricing_unit']} but its standard tier has no price_per_unit (would always bill 0)"
        );
    }
});

it('gives token-based models numeric input/output prices on the standard tier', function () {
    foreach (spectraCatalogRows() as [$provider, $name, $model, $tiers]) {
        if ($model['pricing_unit'] !== PricingUnit::Tokens->value) {
            continue;
        }

        $standard = collect($tiers)->firstWhere('tier', 'standard');

        Assert::assertIsNumeric($standard['input_price'], "{$provider}/{$name} standard tier input_price");
        Assert::assertIsNumeric($standard['output_price'], "{$provider}/{$name} standard tier output_price");
    }
});
