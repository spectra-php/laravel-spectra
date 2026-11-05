<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Illuminate\Support\Facades\Cache;

/**
 * The last price check started from the dashboard, kept until someone
 * applies or dismisses it: per provider, the scraped file entries of the
 * changed and new models and the price changes behind them, or why the
 * provider could not be read. Lives in the default cache store; nothing is
 * billed from it until its models are applied to the scraped prices file.
 */
class PricingReview
{
    public const CACHE_KEY = 'spectra:pricing:review';

    /**
     * How long an unreviewed check is kept, in seconds.
     */
    protected const TTL = 7 * 24 * 3600;

    /**
     * Save a check's results.
     *
     * @param  array<string, array<string, mixed>>  $providers  Provider slug => result (see fromScrape() and failed())
     */
    public static function save(array $providers): void
    {
        Cache::put(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'providers' => $providers], self::TTL);
    }

    /**
     * Get the last check, or null when there is none to review.
     *
     * @return array{at: string, providers: array<string, array<string, mixed>>}|null
     */
    public static function get(): ?array
    {
        $review = rescue(fn () => Cache::get(self::CACHE_KEY), null, report: false);

        if (! is_array($review) || ! is_string($review['at'] ?? null) || ! is_array($review['providers'] ?? null)) {
            return null;
        }

        $providers = [];
        foreach ($review['providers'] as $provider => $result) {
            if (is_array($result)) {
                $providers[(string) $provider] = $result;
            }
        }

        return ['at' => $review['at'], 'providers' => $providers];
    }

    /**
     * Drop the last check.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Turn a provider's scrape into what the review keeps.
     *
     * @return array<string, mixed>
     */
    public static function fromScrape(ProviderScrape $scrape, ScrapedPriceMapper $mapper): array
    {
        $result = $scrape->result;

        return [
            ...$scrape->fileSection($result->models),
            'rows' => $scrape->rows,
            'matched_models' => $result->matchedModels,
            'new_models' => $result->newModels,
            'skipped' => $result->skipped,
            'changes' => array_map(fn (PriceChange $change) => [
                'model' => $change->model,
                'tier' => $change->tier,
                'above_input_tokens' => $change->aboveInputTokens,
                'field' => $change->field,
                'label' => $mapper->label($change->field),
                'unit' => $change->unit,
                'old' => $change->old,
                'new' => $change->new,
                'page_line' => $change->pageLine,
            ], $result->changes),
            'error' => null,
        ];
    }

    /**
     * What the review keeps for a provider that could not be read.
     *
     * @return array<string, mixed>
     */
    public static function failed(string $error): array
    {
        return ['models' => [], 'changes' => [], 'new_models' => [], 'skipped' => [], 'error' => $error];
    }
}
