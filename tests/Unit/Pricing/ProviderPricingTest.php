<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Spectra\Pricing\ProviderPricing;

/**
 * ProviderPricing exports the models an app adds in populate() next to the
 * built-in ones from define().
 */
it('merges define and populate models in that order', function () {
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

    $names = Arr::map($pricing->models(), fn ($m) => $m->getInternalName());

    expect($names)->toBe(['built-in-model', 'custom-model']);
});
