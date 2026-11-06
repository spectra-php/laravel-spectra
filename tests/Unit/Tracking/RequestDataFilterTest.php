<?php

declare(strict_types=1);

use Spectra\Support\Tracking\RequestDataFilter;

/**
 * The store_system_prompts and store_tools switches strip each provider's
 * system prompt and tool definitions from the stored request body, and
 * inline files are always replaced by a placeholder.
 */
it('removes the system prompt wherever the provider puts it', function (array $body, array $expected) {
    expect((new RequestDataFilter)->withoutSystemPrompts($body))->toBe($expected);
})->with([
    'anthropic system key' => [
        ['model' => 'claude-sonnet-4-5', 'system' => 'rules', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        ['model' => 'claude-sonnet-4-5', 'messages' => [['role' => 'user', 'content' => 'hi']]],
    ],
    'gemini systemInstruction' => [
        ['systemInstruction' => ['parts' => [['text' => 'rules']]], 'contents' => [['parts' => [['text' => 'hi']]]]],
        ['contents' => [['parts' => [['text' => 'hi']]]]],
    ],
    'responses instructions and developer input item' => [
        ['model' => 'gpt-5', 'instructions' => 'rules', 'input' => [['role' => 'developer', 'content' => 'more rules'], ['role' => 'user', 'content' => 'hi']]],
        ['model' => 'gpt-5', 'input' => [['role' => 'user', 'content' => 'hi']]],
    ],
    'model input object' => [
        ['version' => 'abc', 'input' => ['prompt' => 'hi', 'system_prompt' => 'rules']],
        ['version' => 'abc', 'input' => ['prompt' => 'hi']],
    ],
    'string input untouched' => [
        ['model' => 'gpt-5', 'input' => 'hi'],
        ['model' => 'gpt-5', 'input' => 'hi'],
    ],
]);

it('removes tool and legacy function definitions', function () {
    $body = ['model' => 'gpt-4o', 'tools' => [['type' => 'function']], 'functions' => [['name' => 'f']], 'tool_choice' => 'auto'];

    expect((new RequestDataFilter)->withoutTools($body))->toBe(['model' => 'gpt-4o', 'tool_choice' => 'auto']);
});

it('replaces inline files anywhere in the body and leaves other strings alone', function () {
    $body = [
        'model' => 'gpt-4.1',
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'data: is not a file'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
            ['type' => 'input_audio', 'input_audio' => ['data' => 'data:audio/wav;base64,UklGRg==', 'format' => 'wav']],
        ]]],
        'image' => 'https://example.test/a.png',
    ];

    $filtered = (new RequestDataFilter)->withoutInlineFiles($body);

    expect($filtered['messages'][0]['content'][1]['image_url']['url'])->toBe('[stripped]')
        ->and($filtered['messages'][0]['content'][2]['input_audio']['data'])->toBe('[stripped]')
        ->and($filtered['messages'][0]['content'][0]['text'])->toBe('data: is not a file')
        ->and($filtered['image'])->toBe('https://example.test/a.png')
        ->and($filtered['model'])->toBe('gpt-4.1');
});
