<?php

declare(strict_types=1);

use Spectra\Providers\Google\GeminiUsage;
use Spectra\Providers\Google\Streaming\GenerateContentStreaming;

/**
 * How Gemini usage is read: thinking and tool-use tokens, modalities,
 * service tiers, search grounding, streamed JSON arrays and Veo job ids.
 */
it('folds thinking and tool-use prompt tokens into the totals', function () {
    $tokens = GeminiUsage::tokens([
        'promptTokenCount' => 100,
        'candidatesTokenCount' => 20,
        'toolUsePromptTokenCount' => 30,
        'thoughtsTokenCount' => 50,
        'cachedContentTokenCount' => 40,
        'totalTokenCount' => 200,
    ]);

    expect($tokens->promptTokens)->toBe(130)
        ->and($tokens->completionTokens)->toBe(70)
        ->and($tokens->reasoningTokens)->toBe(50)
        ->and($tokens->cachedTokens)->toBe(40)
        ->and($tokens->totalTokens())->toBe(200);
});

it('splits uncached audio input and image and audio output by modality', function () {
    $tokens = GeminiUsage::tokens([
        'promptTokenCount' => 1000,
        'candidatesTokenCount' => 1300,
        'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 100], ['modality' => 'AUDIO', 'tokenCount' => 900]],
        'cacheTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 300]],
        'candidatesTokensDetails' => [['modality' => 'IMAGE', 'tokenCount' => 1290], ['modality' => 'TEXT', 'tokenCount' => 10]],
    ]);

    expect($tokens->audioInputTokens)->toBe(600)
        ->and($tokens->imageOutputTokens)->toBe(1290)
        ->and($tokens->audioOutputTokens)->toBe(0);
});

it('maps every documented service tier spelling', function (mixed $value, ?string $tier) {
    expect(GeminiUsage::pricingTier($value))->toBe($tier);
})->with([
    ['flex', 'flex'],
    ['FLEX', 'flex'],
    ['SERVICE_TIER_PRIORITY', 'priority'],
    ['priority', 'priority'],
    ['standard', 'standard'],
    ['unspecified', 'standard'],
    ['SERVICE_TIER_UNSPECIFIED', 'standard'],
    ['something-else', null],
    [null, null],
]);

it('reads the tier from the request and the response', function () {
    $google = $this->googleProvider();

    expect($google->extractPricingTierFromRequest(['serviceTier' => 'flex']))->toBe('flex')
        ->and($google->extractPricingTierFromRequest(['service_tier' => 'priority']))->toBe('priority')
        ->and($google->extractPricingTierFromRequest(['contents' => []]))->toBeNull()
        ->and($google->extractPricingTierFromRequest(['parameters' => ['resolution' => '1080p']]))->toBe('hd')
        ->and($google->extractPricingTierFromRequest(['parameters' => ['resolution' => '720p']]))->toBeNull()
        ->and($google->extractPricingTierFromResponse(['usageMetadata' => ['serviceTier' => 'priority']]))->toBe('priority')
        ->and($google->extractPricingTierFromResponse(['candidates' => []]))->toBeNull();
});

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

it('leaves thought summaries out of streamed text', function () {
    $stream = new GenerateContentStreaming;

    expect($stream->text(['candidates' => [['content' => ['parts' => [['text' => 'thinking', 'thought' => true]]]]]]))->toBeNull()
        ->and($stream->text(['candidates' => [['content' => ['parts' => [['text' => 'a'], ['text' => 'b']]]]]]))->toBe('ab');
});

it('identifies a Veo job by its operation name', function () {
    $operation = ['name' => 'models/veo-3.1-generate-preview/operations/abc', 'done' => true];

    expect($this->googleProvider()->extractResponseId($operation))->toBe('models/veo-3.1-generate-preview/operations/abc')
        ->and($this->googleVideoHandler()->matchesEndpoint('/v1beta/models/veo-3.1-generate-preview/operations/abc'))->toBeTrue();
});
