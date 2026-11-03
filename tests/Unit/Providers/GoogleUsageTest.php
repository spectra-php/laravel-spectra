<?php

declare(strict_types=1);

use Spectra\Providers\Google\GeminiUsage;

/**
 * How Gemini usage is read: service tier spellings, search and maps
 * grounding, and a streamed JSON array folded into one response.
 */
it('maps every documented service tier spelling', function (mixed $value, ?string $tier) {
    expect(GeminiUsage::pricingTier($value))->toBe($tier);
})->with([
    ['FLEX', 'flex'],
    ['SERVICE_TIER_PRIORITY', 'priority'],
    ['SERVICE_TIER_UNSPECIFIED', 'standard'],
    ['something-else', null],
    [null, null],
]);

it('counts search queries and maps grounding', function () {
    $calls = GeminiUsage::groundingCalls(['candidates' => [[
        'groundingMetadata' => [
            'webSearchQueries' => ['a', 'b', 'c'],
            'groundingChunks' => [['web' => ['uri' => 'https://example.com']], ['maps' => ['placeId' => 'places/1']]],
        ],
    ]]]);

    expect($calls)->toBe(['google_search' => 3, 'google_maps' => 1])
        ->and(GeminiUsage::groundingCalls(['candidates' => [['content' => ['parts' => []]]]]))->toBe([]);
});

it('folds a streamed JSON array into one response', function () {
    $merged = GeminiUsage::mergeChunks([
        ['candidates' => [['content' => ['parts' => [['text' => 'Plan first.', 'thought' => true]], 'role' => 'model']]], 'modelVersion' => 'gemini-3.8-flash'],
        ['candidates' => [['content' => ['parts' => [['text' => 'Hel']], 'role' => 'model']]], 'usageMetadata' => ['promptTokenCount' => 5]],
        ['candidates' => [['content' => ['parts' => [['text' => 'lo']], 'role' => 'model'], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2], 'responseId' => 'r1'],
    ]);

    expect($merged['candidates'][0]['content']['parts'])->toBe([
        ['text' => 'Plan first.', 'thought' => true],
        ['text' => 'Hello'],
    ])
        ->and($merged['candidates'][0]['finishReason'])->toBe('STOP')
        ->and($merged['usageMetadata'])->toBe(['promptTokenCount' => 5, 'candidatesTokenCount' => 2])
        ->and($merged['modelVersion'])->toBe('gemini-3.8-flash')
        ->and($merged['responseId'])->toBe('r1')
        ->and($this->googleProvider()->extractResponse($merged))->toBe('Hello');
});
