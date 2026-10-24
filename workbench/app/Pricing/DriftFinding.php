<?php

declare(strict_types=1);

namespace Workbench\App\Pricing;

/**
 * One difference between the pricing catalog and models.dev. Prices are in
 * cents per 1M tokens.
 */
final class DriftFinding
{
    public const PRICE = 'price';

    public const MISSING = 'missing';

    private function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $field,
        public readonly ?float $spectra,
        public readonly ?float $modelsDev,
        public readonly ?float $modelsDevOutput = null,
    ) {}

    public static function price(string $provider, string $model, string $field, float $spectra, float $modelsDev): self
    {
        return new self(self::PRICE, $provider, $model, $field, $spectra, $modelsDev);
    }

    public static function missing(string $provider, string $model, ?float $input, ?float $output): self
    {
        return new self(self::MISSING, $provider, $model, 'model', null, $input, $output);
    }

    /**
     * Identifies the finding in the baseline.
     */
    public function key(): string
    {
        return "{$this->provider}|{$this->model}|{$this->field}";
    }

    /**
     * What the baseline records, so the finding comes back when models.dev
     * changes its price.
     */
    public function baselineValue(): ?float
    {
        return $this->kind === self::PRICE ? $this->modelsDev : null;
    }
}
