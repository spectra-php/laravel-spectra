<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spectra\Enums\PricingUnit;
use Spectra\Pricing\ProviderPricing;
use Throwable;

/**
 * Reads a pricing page with Firecrawl's JSON extraction.
 *
 * Firecrawl renders the page and an LLM fills the schema below, so the rows
 * are a draft: ScrapedPriceMapper checks them against the page's markdown,
 * which is requested in the same call, and against the catalog.
 *
 * @see https://docs.firecrawl.dev/features/llm-extract
 */
class FirecrawlPriceScraper
{
    /** Firecrawl rejects page timeouts above five minutes. */
    protected const MAX_PAGE_TIMEOUT_MS = 300_000;

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.firecrawl.dev/v2',
        protected int $timeout = 180,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('spectra.costs.firecrawl.api_key'),
            (string) (config('spectra.costs.firecrawl.url') ?: 'https://api.firecrawl.dev/v2'),
            (int) (config('spectra.costs.firecrawl.timeout') ?: 180),
        );
    }

    /**
     * The price rows Firecrawl extracted from the page, with the page text.
     *
     * @throws RuntimeException When Firecrawl fails or returns no prices.
     */
    public function scrape(ProviderPricing $pricing, string $url): ScrapedPage
    {
        $shape = CatalogShape::of($pricing);
        $pageTimeout = min($this->timeout * 1000, self::MAX_PAGE_TIMEOUT_MS);

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(intdiv($pageTimeout, 1000) + 30)
                ->post(rtrim($this->baseUrl, '/').'/scrape', [
                    'url' => $url,
                    'formats' => [
                        'markdown',
                        [
                            'type' => 'json',
                            'prompt' => $this->prompt($pricing, $shape),
                            'schema' => static::schema($shape),
                        ],
                    ],
                    'timeout' => $pageTimeout,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Firecrawl couldn't read {$url}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed() || $response->json('success') !== true) {
            $error = $response->json('error');

            throw new RuntimeException("Firecrawl couldn't read {$url}: ".(is_string($error) ? $error : "HTTP {$response->status()}"));
        }

        $models = $response->json('data.json.models');
        $markdown = $response->json('data.markdown');

        if (! is_array($models)) {
            throw new RuntimeException("Firecrawl found no prices on {$url}.");
        }

        return new ScrapedPage($url, array_values(array_filter($models, 'is_array')), is_string($markdown) ? $markdown : '');
    }

    /**
     * The JSON schema Firecrawl fills, one entry per model, tier and
     * prompt-size threshold, with the tiers and prices the catalog uses.
     * Amounts are copied as printed; ScrapedPriceMapper converts them with
     * `priced_per`, because the LLM is unreliable at arithmetic.
     *
     * @return array<string, mixed>
     */
    public static function schema(CatalogShape $shape): array
    {
        $price = fn (string $description) => ['type' => ['number', 'null'], 'description' => $description];
        $prices = array_filter([
            'input' => $price('Input tokens.'),
            'output' => $price('Output tokens.'),
            'cached_input' => $price('Cached input tokens (cache hits / reads).'),
            'cache_write_5m' => $price('Cache writes (5-minute or 30-minute, or the only cache write price).'),
            'cache_write_1h' => $price('1-hour cache writes.'),
            'audio_input' => $price('Audio input tokens.'),
            'audio_output' => $price('Audio output tokens.'),
            'image_input' => $price('Image input tokens.'),
            'image_output' => $price('Image output tokens.'),
            'price_per_unit' => $price('For models not billed per token: the price per unit (image, minute, second, character, ...).'),
        ], fn (string $field) => $shape->allowsField(CatalogShape::FIELDS[$field]), ARRAY_FILTER_USE_KEY);

        return [
            'type' => 'object',
            'properties' => [
                'models' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'model_id' => ['type' => 'string', 'description' => 'The model id used in API requests.'],
                            'display_name' => ['type' => ['string', 'null']],
                            'tier' => ['type' => 'string', 'enum' => $shape->tierLabels()],
                            'above_input_tokens' => [
                                'type' => ['integer', 'null'],
                                'description' => 'Set only when these prices apply to prompts longer than this many tokens.',
                            ],
                            ...$prices,
                            'priced_per' => [
                                'type' => ['integer', 'null'],
                                'description' => 'How many tokens, characters or units the printed prices are for: 1000000 for "per 1M tokens", 1000 for "per 1K characters", 1 for "per image".',
                            ],
                            'unit' => [
                                'type' => ['string', 'null'],
                                'enum' => [...array_column(PricingUnit::cases(), 'value'), null],
                            ],
                        ],
                        'required' => ['model_id', 'tier'],
                    ],
                ],
            ],
            'required' => ['models'],
        ];
    }

    protected function prompt(ProviderPricing $pricing, CatalogShape $shape): string
    {
        $known = [];
        foreach ($pricing->models() as $definition) {
            $model = $definition->toModelArray();

            if ($model['retired_on'] !== null) {
                continue;
            }

            $known[] = $model['pricing_unit'] === 'tokens'
                ? $definition->getInternalName()
                : "{$definition->getInternalName()} (per {$model['pricing_unit']})";
        }

        $tiers = array_intersect_key([
            'standard' => '"standard" for regular prices',
            'batch' => '"batch" for Batch API prices',
            'flex' => '"flex" for Flex processing',
            'priority' => '"priority" for Priority processing',
            'fast' => '"fast" for Fast mode',
            'ultrafast' => '"ultrafast" for Ultrafast processing',
            'hd' => '"hd" for HD quality media',
        ], array_flip($shape->tierLabels()));

        $currency = strtoupper($pricing->currency());

        $lines = [
            'Extract the API price of every AI model listed on this page: every row of every pricing table.',
            "Copy every amount exactly as printed on the page, in {$currency}. Do not convert, multiply or divide it.",
            'Set priced_per to the quantity the printed prices are for: 1000000 when the page prices per 1M tokens, 1000 when per 1K tokens or characters, 1 when per image, minute, second or request.',
            'Make one entry per model and tier, using the tier the page labels the prices with. Tiers: '.implode(', ', $tiers).'. Skip prices for any other tier.',
            'When a table has separate rows per modality (Text, Audio, Image), input/output/cached input are the Text row; put the Audio row in audio_input/audio_output and the Image row in image_input/image_output.',
            'When a price applies only to long prompts (for example "> 200K tokens"), add a separate entry with above_input_tokens set to the threshold (200000).',
            'Models billed per image, megapixel, minute, second, video, search, page, request or character: put the printed price in price_per_unit and set unit. Token models: unit is "tokens".',
            'Only use prices printed on this page for that exact model. Leave a field null when the page does not show it. Never guess, compute or copy prices from another model.',
            'model_id must be the id used in API requests.',
        ];

        if ($known !== []) {
            $lines[] = 'When the page lists one of these models, use exactly this id: '.implode(', ', $known).'.';
        }

        return implode("\n", $lines);
    }
}
