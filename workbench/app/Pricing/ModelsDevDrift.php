<?php

declare(strict_types=1);

namespace Workbench\App\Pricing;

use Spectra\Enums\PricingUnit;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Compares the pricing catalog with models.dev (https://models.dev/api.json).
 *
 * A maintainer tool, not a price source: models.dev is community-maintained
 * and only knows token prices, so every finding still has to be checked
 * against the provider's own pricing page before the catalog changes.
 *
 * Models are matched with the same lookup Spectra uses at runtime (aliases,
 * snapshot dates, routing suffixes), and Spectra's prices are compared as they
 * are billed: a missing cached-input price counts as the input price, and so on.
 */
class ModelsDevDrift
{
    /**
     * Spectra provider slug => models.dev provider id, for catalogs priced in
     * tokens. Replicate, Fal.ai and ElevenLabs are not on models.dev.
     */
    public const PROVIDERS = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'google' => 'google',
        'xai' => 'xai',
        'mistral' => 'mistral',
        'openrouter' => 'openrouter',
        'cohere' => 'cohere',
        'groq' => 'groq',
        'scaleway' => 'scaleway',
    ];

    /**
     * Catalogs that deliberately cover only popular models, so models missing
     * from them are not reported. OpenRouter reports each request's cost itself.
     */
    public const PARTIAL_CATALOGS = ['openrouter'];

    /** Prices this close (relative) count as equal. */
    protected const TOLERANCE = 0.001;

    public function __construct(
        protected PricingLookup $lookup,
    ) {}

    /**
     * @param  array<string, mixed>  $catalog  The decoded models.dev api.json.
     * @return list<DriftFinding>
     */
    public function compare(array $catalog): array
    {
        $findings = [];

        foreach (self::PROVIDERS as $provider => $modelsDevId) {
            $models = $catalog[$modelsDevId]['models'] ?? [];

            if (! is_array($models)) {
                continue;
            }

            foreach ($models as $id => $model) {
                if (! is_array($model) || ! is_array($model['cost'] ?? null)) {
                    continue;
                }

                $findings = [...$findings, ...$this->compareModel($provider, (string) $id, $model)];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<DriftFinding>
     */
    protected function compareModel(string $provider, string $id, array $model): array
    {
        if (! $this->lookup->knows($provider, $id)) {
            $input = $this->usdToCents($model['cost']['input'] ?? null);
            $output = $this->usdToCents($model['cost']['output'] ?? null);

            // A $0 entry is how models.dev lists a model it has no price for.
            $reportable = ($input > 0 || $output > 0)
                && ($model['status'] ?? null) !== 'deprecated'
                && ! in_array($provider, self::PARTIAL_CATALOGS, true);

            return $reportable ? [DriftFinding::missing($provider, $id, $input, $output)] : [];
        }

        // Image, audio and video models are billed per unit; models.dev has no unit prices.
        if ($this->lookup->getUnit($provider, $id) !== PricingUnit::Tokens->value) {
            return [];
        }

        // Prices in another currency can't be compared with models.dev's USD figures.
        if ($this->lookup->currency($provider) !== 'USD') {
            return [];
        }

        $cost = $model['cost'];
        $findings = $this->compareRow($provider, $id, $this->lookup->get($provider, $id), $cost, '');

        foreach ($cost['tiers'] ?? [] as $tier) {
            if (! is_array($tier) || ($tier['tier']['type'] ?? null) !== 'context' || ! is_int($tier['tier']['size'] ?? null)) {
                continue;
            }

            $size = $tier['tier']['size'];
            $row = $this->lookup->get($provider, $id, promptTokens: $size + 1);

            $findings = [...$findings, ...$this->compareRow($provider, $id, $row, $tier, " above {$size}")];
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>|null  $row  Spectra's standard price row.
     * @param  array<string, mixed>  $cost  A models.dev cost object (or context tier).
     * @return list<DriftFinding>
     */
    protected function compareRow(string $provider, string $id, ?array $row, array $cost, string $suffix): array
    {
        if ($row === null) {
            return [];
        }

        $input = (float) $row['input'];
        $output = (float) $row['output'];

        // Spectra field => [models.dev field, what Spectra bills when the field is null].
        $fields = [
            'input' => ['input', $input],
            'output' => ['output', $output],
            'cached_input' => ['cache_read', $input],
            'cache_write' => ['cache_write', $input],
            'audio_input' => ['input_audio', $input],
            'audio_output' => ['output_audio', $output],
            'reasoning' => ['reasoning', $output],
        ];

        $findings = [];

        foreach ($fields as $field => [$modelsDevField, $fallback]) {
            $theirs = $this->usdToCents($cost[$modelsDevField] ?? null);

            // A $0 cache, audio or reasoning price on models.dev means it has none.
            if ($theirs === null || ($theirs == 0 && ! in_array($field, ['input', 'output'], true))) {
                continue;
            }

            $ours = match ($field) {
                'cache_write' => $row['cache_write_5m'] ?? $fallback,
                // Spectra bills reasoning tokens as output tokens.
                'reasoning' => $fallback,
                default => $row[$field] ?? $fallback,
            };

            if ($this->equals((float) $ours, $theirs)) {
                continue;
            }

            // models.dev folds image output tokens into `output`.
            if ($field === 'output' && $row['image_output'] !== null && $this->equals((float) $row['image_output'], $theirs)) {
                continue;
            }

            $findings[] = DriftFinding::price($provider, $id, $field.$suffix, (float) $ours, $theirs);
        }

        return $findings;
    }

    protected function usdToCents(mixed $usd): ?float
    {
        return is_int($usd) || is_float($usd) ? round($usd * 100, 6) : null;
    }

    protected function equals(float $a, float $b): bool
    {
        return abs($a - $b) <= max(abs($a), abs($b)) * self::TOLERANCE;
    }
}
