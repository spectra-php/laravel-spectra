<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * Pricing tiers for AI providers.
 *
 * Multiple providers support tiered pricing based on processing requirements:
 * - Batch: Lowest price, for async processing with up to 24-hour turnaround
 * - Flex: Lower price with higher latency, for flexible workloads
 * - Standard: Regular pricing, standard latency (default)
 * - Priority: Higher price for faster processing (OpenAI calls this "Fast")
 * - Fast: Anthropic fast mode (`speed: fast`), priced separately from Priority Tier
 * - Ultrafast: OpenAI's highest-speed tier
 * - Hd: Higher-quality output for per-image / per-second media models
 *
 * @see https://platform.openai.com/docs/pricing
 * @see https://docs.anthropic.com/en/docs/about-claude/pricing
 */
enum PricingTier: string
{
    case Batch = 'batch';
    case Flex = 'flex';
    case Standard = 'standard';
    case Priority = 'priority';
    case Fast = 'fast';
    case Ultrafast = 'ultrafast';
    case Hd = 'hd';

    /**
     * Get the tier a request is billed at when nothing names one.
     */
    public static function default(): self
    {
        return self::Standard;
    }

    /**
     * Get the tier's name as the dashboard shows it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Batch => 'Batch (Async)',
            self::Flex => 'Flex (Higher Latency)',
            self::Standard => 'Standard',
            self::Priority => 'Priority (Faster)',
            self::Fast => 'Fast Mode',
            self::Ultrafast => 'Ultrafast',
            self::Hd => 'HD (Higher Quality)',
        };
    }

    /**
     * Get the one-sentence explanation of the tier shown on the dashboard.
     */
    public function description(): string
    {
        return match ($this) {
            self::Batch => 'Lowest cost for async processing with up to 24-hour turnaround.',
            self::Flex => 'Lower cost with higher latency for flexible workloads.',
            self::Standard => 'Regular pricing with standard latency.',
            self::Priority => 'Higher cost for faster processing and guaranteed capacity.',
            self::Fast => 'Premium price for higher output speed (Anthropic fast mode).',
            self::Ultrafast => 'Highest price for the fastest output (OpenAI ultrafast).',
            self::Hd => 'Higher cost for higher output quality (e.g. HD images, 1080p video).',
        };
    }
}
