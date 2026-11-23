<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

use RuntimeException;
use Spectra\Support\Pricing\Feeds\Concerns\DownloadsFeed;

/**
 * The prices models.dev lists, used as a second opinion on a new model.
 *
 * models.dev (https://models.dev) is a community-maintained catalog of token
 * prices. It is not a price source for Spectra: the pricing sync only asks it
 * whether a model a feed wants to add is listed there at the same price, so
 * one source alone never adds a model.
 */
class ModelsDevPrices
{
    use DownloadsFeed;

    public const SOURCE = 'https://models.dev/api.json';

    /** Spectra provider slug => models.dev provider id, for the providers a feed can add models to. */
    protected const PROVIDERS = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'google' => 'google',
        'xai' => 'xai',
        'mistral' => 'mistral',
        'cohere' => 'cohere',
        'groq' => 'groq',
        'together' => 'togetherai',
        'fireworks' => 'fireworks-ai',
        'deepinfra' => 'deepinfra',
        'cerebras' => 'cerebras',
    ];

    /**
     * Create a new instance from the decoded models.dev catalog.
     *
     * @param  array<array-key, mixed>  $catalog
     */
    public function __construct(
        protected array $catalog,
    ) {}

    /**
     * Download the catalog from `spectra.costs.feeds.models_dev`.
     *
     * @throws RuntimeException When models.dev can't be reached or isn't JSON.
     */
    public static function fetch(): self
    {
        return new self(static::download((string) (config('spectra.costs.feeds.models_dev') ?: self::SOURCE), 'models.dev'));
    }

    /**
     * Get a model's name and its input and output price in USD per 1M tokens,
     * or null when models.dev doesn't list it for the provider or marks it deprecated.
     *
     * @return array{name: string|null, input: float|null, output: float|null}|null
     */
    public function find(string $provider, string $model): ?array
    {
        $listed = $this->catalog[self::PROVIDERS[$provider] ?? '']['models'][$model] ?? null;

        if (! is_array($listed) || ($listed['status'] ?? null) === 'deprecated') {
            return null;
        }

        $cost = is_array($listed['cost'] ?? null) ? $listed['cost'] : [];

        return [
            'name' => is_string($listed['name'] ?? null) ? $listed['name'] : null,
            'input' => is_numeric($cost['input'] ?? null) ? (float) $cost['input'] : null,
            'output' => is_numeric($cost['output'] ?? null) ? (float) $cost['output'] : null,
        ];
    }
}
