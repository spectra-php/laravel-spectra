<?php

declare(strict_types=1);

namespace Spectra\Pricing;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Support\Pricing\ModelDefinition;
use Throwable;

/**
 * One provider's price catalog, read from a pricing file.
 *
 * Models use the fields of ModelDefinition in snake_case, keyed by model id
 * (PricingFile documents the file's shape). A model that fails to parse, or
 * has no `standard` tier to fall back to, is skipped with a warning, so one
 * bad entry never takes cost tracking down.
 *
 * A catalog from the app's own pricing file sits on top of the bundled
 * catalog of the same provider (`$base`). Its entries replace the bundled
 * models wholesale, so an entry's `pricing_unit` and `type` default to the
 * bundled model's rather than to tokens and text: a hand-written entry for
 * a per-minute or per-image model that leaves them out would otherwise turn
 * it into a token model that bills nothing. An entry that names a different
 * unit from the bundled model is skipped.
 *
 * @implements Arrayable<string, mixed>
 */
class ProviderPricing implements Arrayable
{
    /** Price fields of a tier row, in ModelDefinition::tier() order. */
    public const PRICE_FIELDS = [
        'input_price',
        'output_price',
        'cached_input_price',
        'cache_write_5m_price',
        'cache_write_1h_price',
        'price_per_unit',
        'audio_input_price',
        'audio_output_price',
        'image_input_price',
        'image_output_price',
    ];

    protected const CAPABILITIES = [
        'text' => 'canGenerateText',
        'images' => 'canGenerateImages',
        'video' => 'canGenerateVideo',
        'audio' => 'canGenerateAudio',
    ];

    /** @var list<ModelDefinition>|null */
    protected ?array $definitions = null;

    /**
     * Create a new catalog for one provider's entries in a pricing file.
     *
     * @param  array<array-key, mixed>  $data  Model id => model array (a numeric id decodes as an int key).
     * @param  list<string>  $pageSources  The pricing pages spectra:pricing-scrape reads for the provider.
     * @param  array<string, float>  $toolCalls  Tool call type => cents per call.
     * @param  ProviderPricing|null  $base  The bundled catalog whose models these entries replace.
     * @param  string  $origin  Where the entries come from, for warnings.
     */
    public function __construct(
        protected string $slug,
        protected array $data = [],
        protected string $catalogCurrency = 'USD',
        protected array $pageSources = [],
        protected array $toolCalls = [],
        protected ?ProviderPricing $base = null,
        protected string $origin = 'the pricing file',
    ) {}

    /**
     * Build a catalog from a provider's section of a pricing file.
     *
     * The section's `currency` defaults to the base catalog's. Unknown keys
     * (`notes`, `scraped_at`) are ignored here and kept by the scrape command.
     *
     * @param  array<string, mixed>  $section
     */
    public static function fromSection(string $slug, array $section, ?ProviderPricing $base = null, string $origin = 'the pricing file'): self
    {
        $models = is_array($section['models'] ?? null) ? $section['models'] : [];
        $sources = array_values(Arr::where(Arr::map((array) ($section['sources'] ?? []), fn (mixed $url): string => is_string($url) ? trim($url) : ''), fn (string $url) => $url !== ''));

        $toolCalls = [];
        foreach ((array) ($section['tool_calls'] ?? []) as $type => $cents) {
            if (is_numeric($cents)) {
                $toolCalls[(string) $type] = (float) $cents;
            } else {
                Log::warning("Spectra: skipped the {$slug} tool call price '{$type}' in {$origin}: it must be a number of cents.");
            }
        }

        $currency = Str::upper((string) ($section['currency'] ?? $base?->currency() ?? 'USD'));

        return new self($slug, $models, $currency, $sources, $toolCalls, $base, $origin);
    }

    /**
     * Get the provider slug (e.g. 'openai', 'anthropic').
     */
    public function provider(): string
    {
        return $this->slug;
    }

    /**
     * Get the ISO 4217 code of the currency this catalog's prices are written in.
     *
     * Costs are converted to `spectra.costs.currency` with the rates in
     * `spectra.costs.exchange_rates` when the two differ.
     */
    public function currency(): string
    {
        return $this->catalogCurrency;
    }

    /**
     * Get the pricing pages `spectra:pricing-scrape` reads for this provider.
     *
     * Empty for a provider that is left out of scraping.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->pageSources;
    }

    /**
     * Get the price of each separately billed tool call, in cents per call.
     *
     * Keys match the output item types counted from the response (e.g.
     * `web_search_call`) or the keys a handler reports in Metrics::$toolCalls.
     *
     * @return array<string, float>
     */
    public function toolCallPricing(): array
    {
        return $this->toolCalls;
    }

    /**
     * Get every model in the catalog. Entries are parsed on first access, and
     * an entry that fails to parse is skipped with a warning.
     *
     * @return list<ModelDefinition>
     */
    public function models(): array
    {
        return $this->definitions ??= $this->define();
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

    /**
     * Get the catalog as a provider section of a pricing file, with every
     * model the catalog could parse.
     *
     * @return array{currency: string, sources?: list<string>, tool_calls?: array<string, float>, models: array<string, array<string, mixed>>}
     */
    public function toSection(): array
    {
        $models = [];
        foreach ($this->models() as $definition) {
            $models[$definition->getInternalName()] = static::definitionToArray($definition);
        }

        $section = ['currency' => Str::upper($this->currency())];

        if ($this->sources() !== []) {
            $section['sources'] = $this->sources();
        }

        if ($this->toolCallPricing() !== []) {
            $section['tool_calls'] = $this->toolCallPricing();
        }

        $section['models'] = $models;

        return $section;
    }

    /**
     * Parse each entry, skipping with a warning any that fails.
     *
     * @return list<ModelDefinition>
     */
    protected function define(): array
    {
        $baseModels = $this->baseModels();
        $definitions = [];

        foreach ($this->data as $id => $model) {
            try {
                throw_unless(is_array($model), InvalidArgumentException::class, 'the model must be an object');

                $definitions[] = static::definitionFromArray((string) $id, $this->withBaseUnit((string) $id, $model, $baseModels));
            } catch (Throwable $e) {
                Log::warning("Spectra: skipped the {$this->slug} model '{$id}' in {$this->origin}: {$e->getMessage()}");
            }
        }

        return $definitions;
    }

    /**
     * Fill an entry's unit and type from the base model it replaces, and
     * refuse a unit that differs from it: the entry would bill the model in
     * units its handler never produces, so every call would cost $0.
     *
     * @param  array<mixed>  $model
     * @param  array<string, array{type: string, pricing_unit: string}>  $baseModels
     * @return array<mixed>
     */
    protected function withBaseUnit(string $id, array $model, array $baseModels): array
    {
        $base = $baseModels[$id] ?? null;

        if ($base === null) {
            return $model;
        }

        $unit = $model['pricing_unit'] ?? null;

        throw_if(
            $unit !== null && (string) $unit !== $base['pricing_unit'],
            InvalidArgumentException::class,
            "pricing_unit '{$unit}' differs from the bundled model's '{$base['pricing_unit']}'; a model keeps its unit",
        );

        $model['pricing_unit'] ??= $base['pricing_unit'];
        $model['type'] ??= $base['type'];

        return $model;
    }

    /**
     * Get the type and unit of each base model, by its id and its aliases.
     *
     * @return array<string, array{type: string, pricing_unit: string}>
     */
    protected function baseModels(): array
    {
        if ($this->base === null) {
            return [];
        }

        $models = [];

        foreach ($this->base->models() as $definition) {
            $modelArray = $definition->toModelArray();
            $units = ['type' => $modelArray['type'], 'pricing_unit' => $modelArray['pricing_unit']];

            $models[$definition->getInternalName()] = $units;

            foreach ($definition->getAliases() as $alias) {
                $models[$alias] = $units;
            }
        }

        return $models;
    }

    /**
     * Build a model definition from its JSON form.
     *
     * @param  array<mixed>  $model
     */
    public static function definitionFromArray(string $id, array $model): ModelDefinition
    {
        $definition = new ModelDefinition($id);

        if (isset($model['display_name'])) {
            $definition->displayName((string) $model['display_name']);
        }

        $definition->type((string) ($model['type'] ?? 'text'));
        $definition->pricingUnit((string) ($model['pricing_unit'] ?? PricingUnit::Tokens->value));

        foreach ((array) ($model['capabilities'] ?? []) as $capability) {
            $method = static::CAPABILITIES[$capability] ?? throw new InvalidArgumentException("unknown capability '{$capability}'");
            $definition->{$method}();
        }

        if (filled($model['aliases'] ?? null)) {
            $definition->aliases(...Arr::map((array) $model['aliases'], fn (mixed $alias) => (string) $alias));
        }

        if (isset($model['retired_on'])) {
            $definition->retiredOn((string) $model['retired_on']);
        }

        if (isset($model['variants'])) {
            throw_unless(is_array($model['variants']), InvalidArgumentException::class, 'variants must be an object');

            $definition->variants($model['variants']);
        }

        $tiers = $model['tiers'] ?? [];

        throw_if(! is_array($tiers) || $tiers === [], InvalidArgumentException::class, 'the model has no tiers');

        foreach ($tiers as $row) {
            throw_unless(is_array($row), InvalidArgumentException::class, 'each tier must be an object');

            $tier = PricingTier::tryFrom((string) ($row['tier'] ?? PricingTier::Standard->value))
                ?? throw new InvalidArgumentException("unknown tier '{$row['tier']}'");

            $prices = [];
            foreach (self::PRICE_FIELDS as $field) {
                $value = $row[$field] ?? null;

                throw_if($value !== null && ! is_numeric($value), InvalidArgumentException::class, "{$field} must be a number");

                $prices[] = $value !== null ? (float) $value : null;
            }

            $definition->tier(
                $tier,
                $prices[0] ?? 0,
                $prices[1] ?? 0,
                ...array_slice($prices, 2),
                aboveInputTokens: isset($row['above_input_tokens']) ? (int) $row['above_input_tokens'] : null,
                since: isset($row['since']) ? (string) $row['since'] : null,
            );
        }

        // Prices fall back to the standard tier, so without one the model
        // would replace the bundled entry and bill nothing.
        throw_unless(in_array(PricingTier::Standard->value, Arr::pluck($definition->toTiersArray(), 'tier'), true), InvalidArgumentException::class, 'the model has no standard tier');

        return $definition;
    }

    /**
     * Get the JSON form of a model definition, without empty fields.
     *
     * @return array<string, mixed>
     */
    public static function definitionToArray(ModelDefinition $definition): array
    {
        $model = $definition->toModelArray();

        return Arr::where([
            'display_name' => $model['display_name'],
            'type' => $model['type'],
            'pricing_unit' => $model['pricing_unit'],
            'capabilities' => collect([
                'text' => $model['can_generate_text'],
                'images' => $model['can_generate_images'],
                'video' => $model['can_generate_video'],
                'audio' => $model['can_generate_audio'],
            ])->filter()->keys()->all(),
            'aliases' => $model['aliases'],
            'retired_on' => $model['retired_on'],
            'variants' => $model['variants'],
            'tiers' => Arr::map($definition->toTiersArray(), static::tierToArray(...)),
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * Get the JSON form of a tier row, without its null prices.
     *
     * @param  array<string, mixed>  $row  A ModelDefinition tier entry.
     * @return array<string, mixed>
     */
    public static function tierToArray(array $row): array
    {
        return Arr::whereNotNull($row);
    }
}
