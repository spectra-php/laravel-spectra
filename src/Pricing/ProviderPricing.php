<?php

declare(strict_types=1);

namespace Spectra\Pricing;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Spectra\Support\Pricing\ModelDefinition;

/**
 * Base class for a provider's price catalog.
 *
 * Each provider has one subclass that lists its models in define() with the
 * ModelDefinition builder; PricingLookup indexes every catalog configured in
 * `spectra.costs.pricing`. Prices live in PHP, not a database, so they ship
 * and are reviewed with the package. Apps extend a catalog (or write one for
 * a custom provider) and add models in populate().
 *
 * @implements Arrayable<string, mixed>
 */
abstract class ProviderPricing implements Arrayable
{
    /** @var ModelDefinition[] */
    protected array $definitions = [];

    protected bool $resolved = false;

    /**
     * Get the provider slug (e.g. 'openai', 'anthropic').
     */
    abstract public function provider(): string;

    /**
     * Get the ISO 4217 code of the currency this catalog's prices are written in.
     *
     * Costs are converted to `spectra.costs.currency` with the rates in
     * `spectra.costs.exchange_rates` when the two differ.
     */
    public function currency(): string
    {
        return 'USD';
    }

    /**
     * Get the pricing pages `spectra:pricing-scrape` reads for this provider.
     *
     * Return an empty array to leave the provider out of scraping.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return [];
    }

    /**
     * Define the built-in models for this provider.
     *
     * Override in each provider pricing class to register models.
     */
    protected function define(): void
    {
        //
    }

    /**
     * Add custom models to this provider.
     *
     * Override this method when extending a built-in pricing class.
     * No need to call parent — built-in models from define() are included automatically.
     */
    protected function populate(): void
    {
        //
    }

    /**
     * Define per-tool-call pricing for this provider.
     *
     * Returns an associative array of tool call type => cost in cents per call.
     * Keys match the output item types counted from the response (e.g.
     * `web_search_call`) or the keys a handler reports in Metrics::$toolCalls.
     * Override in provider pricing classes that have separately-billed tool calls.
     *
     * @return array<string, float>
     */
    public function toolCallPricing(): array
    {
        return [];
    }

    /**
     * Register a model definition using the fluent ModelDefinition builder.
     */
    protected function model(string $internalName, Closure $callback): void
    {
        $definition = new ModelDefinition($internalName);
        $callback($definition);
        $this->definitions[] = $definition;
    }

    /**
     * Get all model definitions (built-in + custom).
     *
     * Calls define() and populate() on first access.
     *
     * @return ModelDefinition[]
     */
    public function models(): array
    {
        if (! $this->resolved) {
            $this->define();
            $this->populate();
            $this->resolved = true;
        }

        return $this->definitions;
    }

    /**
     * Get the catalog as an array of its models with their pricing tiers.
     *
     * @return array{provider: string, currency: string, models: array<int, array{internal_name: string, model: array<string, mixed>, tiers: array<int, array<string, mixed>>}>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider(),
            'currency' => $this->currency(),
            'models' => collect($this->models())->map(fn (ModelDefinition $definition) => [
                'internal_name' => $definition->getInternalName(),
                'model' => $definition->toModelArray(),
                'tiers' => $definition->toTiersArray(),
            ])->all(),
        ];
    }
}
