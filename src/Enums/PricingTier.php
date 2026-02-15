<?php

namespace Spectra\Enums;

/**
 * Pricing tiers for AI providers.
 *
 * Multiple providers support tiered pricing based on processing requirements:
 * - Batch: Lowest price, for async processing with up to 24-hour turnaround
 * - Flex: Lower price with higher latency, for flexible workloads (OpenAI only)
 * - Standard: Regular pricing, standard latency (default)
 * - Priority: Higher price for faster processing and guaranteed capacity
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

    public static function default(): self
    {
        return self::Standard;
    }

    public function label(): string
    {
        return match ($this) {
            self::Batch => 'Batch (Async)',
            self::Flex => 'Flex (Higher Latency)',
            self::Standard => 'Standard',
            self::Priority => 'Priority (Faster)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Batch => 'Lowest cost for async processing with up to 24-hour turnaround.',
            self::Flex => 'Lower cost with higher latency for flexible workloads.',
            self::Standard => 'Regular pricing with standard latency.',
            self::Priority => 'Higher cost for faster processing and guaranteed capacity.',
        };
    }
}
