<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;
use Spectra\Support\Pricing\PricingLookup;

/**
 * Small, stable catalogs for the pricing file, cache and scraping tests, as
 * provider sections of a pricing file.
 */
final class FakeCatalogs
{
    /**
     * An OpenAI-like provider with pricing pages: a text model with a
     * long-context row and a batch tier, a plain text model and an image model.
     *
     * @return array<string, mixed>
     */
    public static function openaiSection(): array
    {
        return [
            'currency' => 'USD',
            'notes' => ['A test catalog.'],
            'sources' => ['https://example.test/pricing'],
            'models' => [
                'gpt-x' => [
                    'display_name' => 'GPT X',
                    'capabilities' => ['text'],
                    'aliases' => ['gpt-x-latest'],
                    'tiers' => [
                        ['tier' => 'standard', 'input_price' => 100, 'output_price' => 400, 'cached_input_price' => 10],
                        ['tier' => 'standard', 'input_price' => 200, 'output_price' => 600, 'above_input_tokens' => 200_000],
                        ['tier' => 'batch', 'input_price' => 50, 'output_price' => 200],
                    ],
                ],
                'gpt-y' => [
                    'display_name' => 'GPT Y',
                    'capabilities' => ['text'],
                    'tiers' => [['tier' => 'standard', 'input_price' => 30, 'output_price' => 120]],
                ],
                'image-x' => [
                    'display_name' => 'Image X',
                    'type' => 'image',
                    'pricing_unit' => 'image',
                    'capabilities' => ['images'],
                    'tiers' => [['tier' => 'standard', 'price_per_unit' => 4], ['tier' => 'hd', 'price_per_unit' => 8]],
                ],
            ],
        ];
    }

    /**
     * A second provider with pricing pages, for choosing which providers to scrape.
     *
     * @return array<string, mixed>
     */
    public static function anthropicSection(): array
    {
        return [
            'currency' => 'USD',
            'sources' => ['https://example.test/anthropic-pricing'],
            'models' => [
                'claude-x' => [
                    'display_name' => 'Claude X',
                    'capabilities' => ['text'],
                    'tiers' => [['tier' => 'standard', 'input_price' => 300, 'output_price' => 1500]],
                ],
            ],
        ];
    }

    /**
     * A provider whose models cover the scraping safeguards: modality prices,
     * a priority tier, a long-context row, an embedding and per-unit models.
     *
     * @return array<string, mixed>
     */
    public static function acmeSection(): array
    {
        return [
            'currency' => 'USD',
            'models' => [
                'realtime-x' => [
                    'capabilities' => ['text'],
                    'tiers' => [
                        ['tier' => 'standard', 'input_price' => 400, 'output_price' => 2400, 'cached_input_price' => 40, 'audio_input_price' => 3200, 'audio_output_price' => 6400],
                        ['tier' => 'priority', 'input_price' => 800, 'output_price' => 4800, 'cached_input_price' => 80, 'audio_input_price' => 6400, 'audio_output_price' => 12800],
                    ],
                ],
                'long-x' => [
                    'capabilities' => ['text'],
                    'tiers' => [
                        ['tier' => 'standard', 'input_price' => 200, 'output_price' => 1200],
                        ['tier' => 'standard', 'input_price' => 400, 'output_price' => 1800, 'above_input_tokens' => 200_000],
                    ],
                ],
                'claude-3-5-haiku' => [
                    'display_name' => 'Claude 3.5 Haiku',
                    'capabilities' => ['text'],
                    'tiers' => [['tier' => 'standard', 'input_price' => 80, 'output_price' => 400]],
                ],
                'embed-v5.0' => [
                    'type' => 'embedding',
                    'tiers' => [['tier' => 'standard', 'input_price' => 10]],
                ],
                'imagine-x' => [
                    'type' => 'image',
                    'pricing_unit' => 'image',
                    'capabilities' => ['images'],
                    'tiers' => [['tier' => 'standard', 'price_per_unit' => 4]],
                ],
                'tts-x' => [
                    'pricing_unit' => 'characters',
                    'capabilities' => ['audio'],
                    'tiers' => [['tier' => 'standard', 'price_per_unit' => 1500]],
                ],
            ],
        ];
    }

    public static function openai(): ProviderPricing
    {
        return ProviderPricing::fromSection('openai', self::openaiSection());
    }

    public static function anthropic(): ProviderPricing
    {
        return ProviderPricing::fromSection('anthropic', self::anthropicSection());
    }

    public static function acme(): ProviderPricing
    {
        return ProviderPricing::fromSection('acme', self::acmeSection());
    }

    /**
     * Write a bundled catalog with the given provider sections (default: openai
     * and anthropic) to a temporary file and return its path.
     *
     * @param  array<string, array<string, mixed>>|null  $sections
     */
    public static function bundledFile(?array $sections = null): string
    {
        $path = storage_path('framework/testing/bundled-'.uniqid().'.json');

        (new PricingFile($path))->write([
            'generated_at' => '2026-11-01T00:00:00+00:00',
            'providers' => $sections ?? ['openai' => self::openaiSection(), 'anthropic' => self::anthropicSection()],
        ]);

        return $path;
    }

    /**
     * Read Spectra's bundled catalog from a temporary file with the given
     * sections for the rest of the test, and return the file's path.
     *
     * @param  array<string, array<string, mixed>>|null  $sections
     */
    public static function useBundled(?array $sections = null): string
    {
        $path = self::bundledFile($sections);

        PricingCatalog::useBundledFile($path);
        app()->forgetInstance(PricingLookup::class);

        return $path;
    }
}
