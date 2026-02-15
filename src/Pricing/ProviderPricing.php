<?php

namespace Spectra\Pricing;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Spectra\Support\Pricing\ModelDefinition;

/**
 * @implements Arrayable<string, mixed>
 */
abstract class ProviderPricing implements Arrayable
{
    /** @var ModelDefinition[] */
    protected array $definitions = [];

    protected bool $resolved = false;

    /**
     * The provider slug (e.g. 'openai', 'anthropic').
     */
    abstract public function provider(): string;

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
     * @return array{provider: string, models: array<int, array{internal_name: string, model: array<string, mixed>, tiers: array<int, array<string, mixed>>}>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider(),
            'models' => array_map(fn (ModelDefinition $def) => [
                'internal_name' => $def->getInternalName(),
                'model' => $def->toModelArray(),
                'tiers' => $def->toTiersArray(),
            ], $this->models()),
        ];
    }
}
