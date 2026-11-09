<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    /**
     * Create a new Firecrawl price scraper instance.
     */
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.firecrawl.dev/v2',
        protected int $timeout = 180,
    ) {}

    /**
     * Create a scraper with the API key, URL and timeout from `spectra.costs.firecrawl`.
     */
    public static function fromConfig(): self
    {
        return new self(
            (string) config('spectra.costs.firecrawl.api_key'),
            (string) (config('spectra.costs.firecrawl.url') ?: 'https://api.firecrawl.dev/v2'),
            (int) (config('spectra.costs.firecrawl.timeout') ?: 180),
        );
    }

    /**
     * Scrape the price rows Firecrawl extracts from the page, with the page text.
     *
     * @throws RuntimeException When Firecrawl fails or returns no prices.
     */
    public function scrape(ProviderPricing $pricing, string $url): ScrapedPage
    {
        $shape = CatalogShape::of($pricing);
        $pageTimeout = min($this->timeout * 1000, self::MAX_PAGE_TIMEOUT_MS);
        $baseUrl = Str::rtrim($this->baseUrl, '/');

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(intdiv($pageTimeout, 1000) + 30)
                ->post("{$baseUrl}/scrape", [
                    'url' => $url,
                    'formats' => [
                        'markdown',
                        [
                            'type' => 'json',
                            'prompt' => $this->buildPrompt($pricing, $shape),
                            'schema' => static::schema($shape),
                        ],
                    ],
                    'timeout' => $pageTimeout,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Firecrawl couldn't read {$url}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        if ($response->failed() || $response->json('success') !== true) {
            $error = $response->json('error');
            $reason = is_string($error) ? $error : "HTTP {$response->status()}";

            throw new RuntimeException("Firecrawl couldn't read {$url}: {$reason}");
        }

        $models = $response->json('data.json.models');
        $markdown = $response->json('data.markdown');

        throw_unless(is_array($models), RuntimeException::class, "Firecrawl found no prices on {$url}.");

        return new ScrapedPage($url, array_values(Arr::where($models, fn (mixed $row) => is_array($row))), is_string($markdown) ? $markdown : '');
    }

    /**
     * Build the JSON schema Firecrawl fills, one entry per model, tier and
     * prompt-size threshold, with the tiers and prices the catalog uses.
     *
     * Amounts are copied as printed; ScrapedPriceMapper converts them with
     * `priced_per`, because the LLM is unreliable at arithmetic.
     *
     * @return array<string, mixed>
     */
    public static function schema(CatalogShape $shape): array
    {
        $price = fn (string $description) => ['type' => ['number', 'null'], 'description' => $description];
        $prices = Arr::where([
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
        ], fn (array $schema, string $field) => $shape->allowsField(CatalogShape::FIELDS[$field]));

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
                                'enum' => [...Arr::pluck(PricingUnit::cases(), 'value'), null],
                            ],
                            'kind' => [
                                'type' => ['string', 'null'],
                                'enum' => [...ScrapedPriceMapper::KINDS, null],
                                'description' => 'What the model generates: text (chat, embeddings, rerank, transcription, OCR), image, audio (speech, music, sound effects), video, 3d, or other.',
                            ],
                        ],
                        'required' => ['model_id', 'tier'],
                    ],
                ],
            ],
            'required' => ['models'],
        ];
    }

    /**
     * Build the extraction prompt, naming the catalog's tiers, currency and known model ids.
     */
    protected function buildPrompt(ProviderPricing $pricing, CatalogShape $shape): string
    {
        $knownModels = [];
        foreach ($pricing->models() as $definition) {
            $model = $definition->toModelArray();

            if ($model['retired_on'] !== null) {
                continue;
            }

            $knownModels[] = $model['pricing_unit'] === PricingUnit::Tokens->value
                ? $definition->getInternalName()
                : "{$definition->getInternalName()} (per {$model['pricing_unit']})";
        }

        $tiers = Arr::only([
            'standard' => '"standard" for regular prices',
            'batch' => '"batch" for Batch API prices',
            'flex' => '"flex" for Flex processing',
            'priority' => '"priority" for Priority processing',
            'fast' => '"fast" for Fast mode',
            'ultrafast' => '"ultrafast" for Ultrafast processing',
            'hd' => '"hd" for HD quality media',
        ], $shape->tierLabels());

        $currency = Str::upper($pricing->currency());
        $tierList = implode(', ', $tiers);

        $lines = [
            'Extract the API price of every AI model listed on this page: every row of every pricing table.',
            "Copy every amount exactly as printed on the page, in {$currency}. Do not convert, multiply or divide it.",
            'Set priced_per to the quantity the printed prices are for: 1000000 when the page prices per 1M tokens, 1000 when per 1K tokens or characters, 1 when per image, minute, second or request.',
            "Make one entry per model and tier, using the tier the page labels the prices with. Tiers: {$tierList}. Skip prices for any other tier.",
            'When a table has separate rows per modality (Text, Audio, Image), input/output/cached input are the Text row; put the Audio row in audio_input/audio_output and the Image row in image_input/image_output.',
            'When a price applies only to long prompts (for example "> 200K tokens"), add a separate entry with above_input_tokens set to the threshold (200000).',
            'Models billed per image, megapixel, minute, second, video, search, page, request or character: put the printed price in price_per_unit and set unit. Token models: unit is "tokens".',
            'Only use prices printed on this page for that exact model. Leave a field null when the page does not show it. Never guess, compute or copy prices from another model.',
            'model_id must be the id used in API requests.',
            'Set kind to what the model generates: text, image, audio, video, 3d or other.',
        ];

        if ($knownModels !== []) {
            $knownList = implode(', ', $knownModels);
            $lines[] = "When the page lists one of these models, use exactly this id: {$knownList}.";
        }

        return implode("\n", $lines);
    }
}
