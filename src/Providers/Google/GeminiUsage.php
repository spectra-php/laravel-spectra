<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

use Spectra\Data\TokenMetrics;
use Spectra\Enums\PricingTier;
use Spectra\Support\Payload;

/**
 * Reads the parts of a Gemini API response that decide its cost.
 *
 * `usageMetadata` reports thinking tokens (`thoughtsTokenCount`) and tool-use
 * prompt tokens (`toolUsePromptTokenCount`) BESIDE the prompt and candidate
 * counts: `totalTokenCount` = prompt + tool-use prompt + thoughts +
 * candidates. Both are billed (thinking at the output rate, tool-use prompt
 * at the input rate), so they are added to the totals TokenMetrics expects.
 * `cachedContentTokenCount` is already inside `promptTokenCount`.
 */
final class GeminiUsage
{
    /**
     * @param  array<string, mixed>  $usageMetadata
     */
    public static function tokens(array $usageMetadata): TokenMetrics
    {
        $thoughts = self::int($usageMetadata, 'thoughtsTokenCount');
        $cached = self::int($usageMetadata, 'cachedContentTokenCount');

        // Audio input has its own (higher) price; only the uncached part of
        // it is billed at that rate, cache reads bill at the cache rate.
        $audioInput = max(0,
            self::modality($usageMetadata['promptTokensDetails'] ?? null, 'AUDIO')
            - self::modality($usageMetadata['cacheTokensDetails'] ?? null, 'AUDIO')
        );

        return new TokenMetrics(
            promptTokens: self::int($usageMetadata, 'promptTokenCount') + self::int($usageMetadata, 'toolUsePromptTokenCount'),
            completionTokens: self::int($usageMetadata, 'candidatesTokenCount') + $thoughts,
            cachedTokens: $cached,
            reasoningTokens: $thoughts,
            audioInputTokens: $audioInput,
            audioOutputTokens: self::modality($usageMetadata['candidatesTokensDetails'] ?? null, 'AUDIO'),
            imageOutputTokens: self::modality($usageMetadata['candidatesTokensDetails'] ?? null, 'IMAGE'),
        );
    }

    /**
     * The usage metadata of a response, or an empty array.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public static function metadata(array $response): array
    {
        return is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
    }

    /**
     * Map a Gemini service tier (`flex`, `PRIORITY`, `SERVICE_TIER_STANDARD`,
     * `unspecified`, ...) to a PricingTier value.
     */
    public static function pricingTier(mixed $serviceTier): ?string
    {
        if (blank($serviceTier)) {
            return null;
        }

        $tier = strtolower((string) preg_replace('/^SERVICE_TIER_/i', '', $serviceTier));

        return match ($tier) {
            'flex' => PricingTier::Flex->value,
            'priority' => PricingTier::Priority->value,
            // "Unspecified" is documented as the default tier, which is standard.
            'standard', 'unspecified', 'default' => PricingTier::Standard->value,
            default => null,
        };
    }

    /**
     * Grounding used by the response, keyed like Metrics::$toolCalls.
     *
     * Search grounding is billed per search query on Gemini 3 and newer, and
     * per grounded prompt on Gemini 2.5, after a free allowance (per day or
     * per month) that a single response can't see. So it is counted here but
     * deliberately has no price in GooglePricing.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    public static function groundingCalls(array $response): array
    {
        $search = 0;
        $maps = 0;

        foreach (is_array($response['candidates'] ?? null) ? $response['candidates'] : [] as $candidate) {
            $grounding = is_array($candidate['groundingMetadata'] ?? null) ? $candidate['groundingMetadata'] : null;

            if ($grounding === null) {
                continue;
            }

            $queries = is_array($grounding['webSearchQueries'] ?? null) ? count($grounding['webSearchQueries']) : 0;
            $chunks = is_array($grounding['groundingChunks'] ?? null) ? $grounding['groundingChunks'] : [];

            $hasWeb = $queries > 0 || isset($grounding['searchEntryPoint'])
                || array_filter($chunks, fn ($chunk) => is_array($chunk) && isset($chunk['web'])) !== [];

            $search += $queries > 0 ? $queries : ($hasWeb ? 1 : 0);

            if (array_filter($chunks, fn ($chunk) => is_array($chunk) && isset($chunk['maps'])) !== []) {
                $maps++;
            }
        }

        return array_filter(['google_search' => $search, 'google_maps' => $maps]);
    }

    /**
     * Tokens of one modality in a `*TokensDetails` list.
     */
    public static function modality(mixed $details, string $modality): int
    {
        if (! is_array($details)) {
            return 0;
        }

        $total = 0;

        foreach ($details as $detail) {
            if (is_array($detail) && strtoupper((string) ($detail['modality'] ?? '')) === $modality) {
                $total += Payload::int($detail['tokenCount'] ?? null);
            }
        }

        return $total;
    }

    /**
     * Fold a streamed `:streamGenerateContent` JSON array into one response.
     *
     * Each element carries a slice of the candidate's parts; usage, model
     * version and response id are cumulative, so the last element's win.
     * Adjacent text slices are joined back into one part.
     *
     * @param  array<mixed>  $chunks
     * @return array<string, mixed>
     */
    public static function mergeChunks(array $chunks): array
    {
        $chunks = array_values(array_filter($chunks, 'is_array'));

        if ($chunks === []) {
            return [];
        }

        $merged = [];
        $parts = [];
        $candidate = [];

        foreach ($chunks as $chunk) {
            foreach (['usageMetadata', 'modelVersion', 'responseId', 'promptFeedback'] as $key) {
                if (isset($chunk[$key])) {
                    $merged[$key] = $chunk[$key];
                }
            }

            $first = is_array($chunk['candidates'][0] ?? null) ? $chunk['candidates'][0] : null;

            if ($first === null) {
                continue;
            }

            foreach (is_array($first['content']['parts'] ?? null) ? $first['content']['parts'] : [] as $part) {
                $parts = self::appendPart($parts, $part);
            }

            $candidate = array_replace($candidate, array_diff_key($first, ['content' => true]));
            $candidate['content']['role'] = $first['content']['role'] ?? $candidate['content']['role'] ?? 'model';
        }

        if ($candidate !== [] || $parts !== []) {
            $candidate['content']['parts'] = $parts;
            $merged['candidates'] = [$candidate];
        }

        return $merged;
    }

    /**
     * Whether a decoded body is a streamed array of response chunks.
     */
    public static function isChunkList(mixed $data): bool
    {
        return is_array($data) && $data !== [] && array_is_list($data) && is_array($data[0])
            && (isset($data[0]['candidates']) || isset($data[0]['usageMetadata']) || isset($data[0]['modelVersion']));
    }

    /**
     * @param  list<mixed>  $parts
     * @return list<mixed>
     */
    protected static function appendPart(array $parts, mixed $part): array
    {
        $last = array_pop($parts);

        $isPlainText = static fn (mixed $p): bool => is_array($p) && is_string($p['text'] ?? null)
            && array_diff(array_keys($p), ['text', 'thought', 'thoughtSignature']) === [];

        if (is_array($last) && is_array($part) && $isPlainText($part) && $isPlainText($last)
            && (bool) ($last['thought'] ?? false) === (bool) ($part['thought'] ?? false)) {
            $last['text'] .= $part['text'];

            if (isset($part['thoughtSignature'])) {
                $last['thoughtSignature'] = $part['thoughtSignature'];
            }

            $part = $last;
        } elseif ($last !== null) {
            $parts[] = $last;
        }

        $parts[] = $part;

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function int(array $data, string $key): int
    {
        return is_numeric($data[$key] ?? null) ? (int) $data[$key] : 0;
    }
}
