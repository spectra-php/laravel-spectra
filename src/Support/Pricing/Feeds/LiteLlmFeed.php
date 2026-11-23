<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds;

use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\Feeds\Concerns\DownloadsFeed;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Reads LiteLLM's model cost map as price rows for spectra:pricing-sync.
 *
 * LiteLLM (https://github.com/BerriAI/litellm) keeps one JSON file with the
 * price of every model it routes to, in USD per token, and updates it several
 * times a day. Its team and contributors maintain it, not the providers, so
 * the rows go through FeedPriceMapper's safeguards and every change to the
 * bundled catalog is still reviewed against the provider's pricing page.
 *
 * Rows are in the shape FeedPriceMapper reads: USD per 1M tokens (or per
 * 1M characters), and USD per unit for models the catalog bills per unit.
 */
class LiteLlmFeed
{
    use DownloadsFeed;

    public const SOURCE = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * Spectra provider slug => the `litellm_provider` values that are this
     * provider's own API, and the prefix LiteLLM puts before its model ids.
     *
     * Scaleway is left out: its catalog is in euros and LiteLLM lists only a
     * couple of its models, in US dollars.
     */
    public const PROVIDERS = [
        'openai' => ['providers' => ['openai', 'text-completion-openai'], 'prefix' => 'openai/'],
        'anthropic' => ['providers' => ['anthropic'], 'prefix' => 'anthropic/'],
        'google' => ['providers' => ['gemini'], 'prefix' => 'gemini/'],
        'xai' => ['providers' => ['xai'], 'prefix' => 'xai/'],
        'mistral' => ['providers' => ['mistral'], 'prefix' => 'mistral/'],
        'cohere' => ['providers' => ['cohere', 'cohere_chat'], 'prefix' => 'cohere/'],
        'groq' => ['providers' => ['groq'], 'prefix' => 'groq/'],
        'elevenlabs' => ['providers' => ['elevenlabs'], 'prefix' => 'elevenlabs/'],
        'falai' => ['providers' => ['fal_ai'], 'prefix' => 'fal_ai/'],
        'replicate' => ['providers' => ['replicate'], 'prefix' => 'replicate/'],
        'together' => ['providers' => ['together_ai'], 'prefix' => 'together_ai/'],
        'fireworks' => ['providers' => ['fireworks_ai'], 'prefix' => 'fireworks_ai/'],
        'deepinfra' => ['providers' => ['deepinfra'], 'prefix' => 'deepinfra/'],
        'cerebras' => ['providers' => ['cerebras'], 'prefix' => 'cerebras/'],
    ];

    /** LiteLLM token price field => the row field the mapper reads. The 1-hour cache write comes first: its name starts like the 5-minute one. */
    protected const TOKEN_FIELDS = [
        'input_cost_per_token' => 'input',
        'output_cost_per_token' => 'output',
        'cache_read_input_token_cost' => 'cached_input',
        'cache_creation_input_token_cost_above_1hr' => 'cache_write_1h',
        'cache_creation_input_token_cost' => 'cache_write_5m',
        'input_cost_per_audio_token' => 'audio_input',
        'output_cost_per_audio_token' => 'audio_output',
        'input_cost_per_image_token' => 'image_input',
        'output_cost_per_image_token' => 'image_output',
    ];

    /** The suffix LiteLLM adds to a price field for a processing tier => the catalog tier. */
    protected const TIER_SUFFIXES = ['batches' => 'batch', 'flex' => 'flex', 'priority' => 'priority'];

    /** Catalog pricing unit => LiteLLM fields that hold its price, first match wins, and what one catalog unit is in theirs. */
    protected const UNIT_FIELDS = [
        'characters' => [['input_cost_per_character'], 1_000_000],
        'minute' => [['input_cost_per_second', 'output_cost_per_second'], 60],
        'second' => [['output_cost_per_second', 'output_cost_per_video_per_second'], 1],
        'image' => [['output_cost_per_image'], 1],
        'megapixel' => [['output_cost_per_image'], 1],
        'page' => [['ocr_cost_per_page'], 1],
        'search' => [['input_cost_per_query'], 1],
    ];

    /** LiteLLM modes whose models are billed per token, so a new one can be added. */
    protected const TOKEN_MODES = ['chat', 'responses', 'completion', 'embedding'];

    /**
     * Create a new feed from the decoded cost map.
     *
     * @param  array<array-key, mixed>  $costMap
     */
    public function __construct(
        protected array $costMap,
    ) {}

    /**
     * Download the cost map from `spectra.costs.feeds.litellm`.
     *
     * @throws RuntimeException When the cost map can't be reached or isn't JSON.
     */
    public static function fetch(): self
    {
        return new self(static::download((string) (config('spectra.costs.feeds.litellm') ?: self::SOURCE), 'LiteLLM'));
    }

    /**
     * Determine if the feed covers the provider.
     */
    public static function covers(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /**
     * Get the feed's price rows for a provider: every tier of the models the
     * catalog has, and the standard prices of token models it doesn't.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(ProviderPricing $pricing): array
    {
        $feed = self::PROVIDERS[$pricing->provider()] ?? null;

        if ($feed === null) {
            return [];
        }

        $lookup = new PricingLookup([$pricing]);
        $definitions = [];
        foreach ($pricing->models() as $definition) {
            $definitions[$definition->getInternalName()] = $definition;
        }

        $rows = [];

        foreach ($this->costMap as $key => $entry) {
            if (! is_array($entry) || ! in_array($entry['litellm_provider'] ?? null, $feed['providers'], true)) {
                continue;
            }

            $id = Str::chopStart((string) $key, $feed['prefix']);
            $known = $definitions[$lookup->canonicalModel($pricing->provider(), $id) ?? ''] ?? null;

            if ($known === null && ! in_array($entry['mode'] ?? null, self::TOKEN_MODES, true)) {
                continue;
            }

            $unit = $known?->toModelArray()['pricing_unit'] ?? PricingUnit::Tokens->value;

            $rows = [...$rows, ...($unit === PricingUnit::Tokens->value
                ? $this->tokenRows($id, $entry)
                : $this->unitRows($id, $entry, $unit))];
        }

        return $rows;
    }

    /**
     * Get one row per tier and long-prompt threshold the entry prices per token.
     *
     * @param  array<array-key, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    protected function tokenRows(string $id, array $entry): array
    {
        $fields = implode('|', array_keys(self::TOKEN_FIELDS));
        $tiers = implode('|', array_keys(self::TIER_SUFFIXES));
        $rows = [];

        foreach ($entry as $field => $value) {
            if (! is_numeric($value) || preg_match("/^({$fields})(?:_above_(\d+)k_tokens)?(?:_({$tiers}))?$/", (string) $field, $match) !== 1) {
                continue;
            }

            $tier = self::TIER_SUFFIXES[$match[3] ?? ''] ?? 'standard';
            $above = filled($match[2] ?? null) ? (int) $match[2] * 1000 : null;

            $rows["{$tier}|{$above}"] ??= [
                'model_id' => $id,
                'tier' => $tier,
                'above_input_tokens' => $above,
                'priced_per' => 1_000_000,
                'unit' => PricingUnit::Tokens->value,
                'kind' => 'text',
            ];
            $rows["{$tier}|{$above}"][self::TOKEN_FIELDS[$match[1]]] = round((float) $value * 1_000_000, 8);
        }

        foreach ($rows as $key => $row) {
            // LiteLLM repeats the text price as the audio and image price when
            // they cost the same; the catalog leaves those empty and bills the text price.
            foreach (['audio_input' => 'input', 'image_input' => 'input', 'audio_output' => 'output', 'image_output' => 'output'] as $modality => $text) {
                if (isset($row[$modality], $row[$text]) && abs($row[$modality] - $row[$text]) < 0.00000001) {
                    unset($rows[$key][$modality]);
                }
            }
        }

        // A model LiteLLM lists without a price has zeros: not a price to apply.
        return array_values(array_filter($rows, fn (array $row) => ($row['input'] ?? 0) > 0 || ($row['output'] ?? 0) > 0));
    }

    /**
     * Get the standard row of a model the catalog bills per unit, when the
     * entry has a price in a unit that converts to it.
     *
     * @param  array<array-key, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    protected function unitRows(string $id, array $entry, string $unit): array
    {
        [$fields, $perCatalogUnit] = self::UNIT_FIELDS[$unit] ?? [[], 1];

        foreach ($fields as $field) {
            $value = $entry[$field] ?? null;

            if (! is_numeric($value) || $value <= 0) {
                continue;
            }

            $isPerCharacter = $unit === PricingUnit::Characters->value;

            return [[
                'model_id' => $id,
                'tier' => 'standard',
                'above_input_tokens' => null,
                'price_per_unit' => round((float) $value * $perCatalogUnit, 8),
                'priced_per' => $isPerCharacter ? 1_000_000 : 1,
                'unit' => $unit,
                'kind' => null,
            ]];
        }

        return [];
    }
}
