<?php

declare(strict_types=1);

use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;
use Spectra\Tests\Concerns\LoadsMockResponses;

/**
 * The plain text of a stored call for the conversation transcript: the last
 * user message from each request shape chat APIs use, and the reply through
 * the provider's handler or a streamed call's accumulated text.
 */
uses(LoadsMockResponses::class);

function storedCall(string $provider, string $endpoint, mixed $response): SpectraRequest
{
    return new SpectraRequest(['provider' => $provider, 'endpoint' => $endpoint, 'response' => $response]);
}

it('reads the last user message of a chat messages list', function () {
    $prompt = app(MessageTextExtractor::class)->prompt(['messages' => [
        ['role' => 'system', 'content' => 'You are helpful.'],
        ['role' => 'user', 'content' => 'First question'],
        ['role' => 'assistant', 'content' => 'First answer'],
        ['role' => 'user', 'content' => 'Where is my order?'],
    ]]);

    expect($prompt)->toBe('Where is my order?');
});

it('keeps only the text parts of a message with images', function () {
    $prompt = app(MessageTextExtractor::class)->prompt(['messages' => [
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'What is in this photo?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/cat.png']],
        ]],
    ]]);

    expect($prompt)->toBe('What is in this photo?');
});

it('reads OpenAI Responses input as a string or as items', function () {
    $extractor = app(MessageTextExtractor::class);

    expect($extractor->prompt(['input' => 'Write a haiku']))->toBe('Write a haiku')
        ->and($extractor->prompt(['input' => [
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Summarize this']]],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":true}'],
        ]]))->toBe('Summarize this');
});

it('reads the last user turn of Gemini contents, with or without a role', function () {
    $extractor = app(MessageTextExtractor::class);

    expect($extractor->prompt(['contents' => [
        ['role' => 'user', 'parts' => [['text' => 'Hi']]],
        ['role' => 'model', 'parts' => [['text' => 'Hello!']]],
        ['role' => 'user', 'parts' => [['text' => 'How does AI work?']]],
    ]]))->toBe('How does AI work?')
        ->and($extractor->prompt(['contents' => [['parts' => [['text' => 'No role here']]]]]))->toBe('No role here');
});

it('reads a bare message or prompt string', function () {
    $extractor = app(MessageTextExtractor::class);

    expect($extractor->prompt(['message' => 'Cohere v1 message']))->toBe('Cohere v1 message')
        ->and($extractor->prompt(['prompt' => 'Ollama generate prompt']))->toBe('Ollama generate prompt');
});

it('has no prompt when the request was not stored or holds no user message', function () {
    $extractor = app(MessageTextExtractor::class);

    expect($extractor->prompt(null))->toBeNull()
        ->and($extractor->prompt('not json'))->toBeNull()
        ->and($extractor->prompt(['messages' => [['role' => 'system', 'content' => 'Only a system prompt']]]))->toBeNull();
});

it('reads the reply through the provider handler', function () {
    $extractor = app(MessageTextExtractor::class);

    expect($extractor->reply(storedCall('openai', '/v1/chat/completions', $this->loadMockResponse('openai/completion.json'))))
        ->toBe('Hello! How can I assist you today?')
        ->and($extractor->reply(storedCall('openai', '/v1/responses', $this->loadMockResponse('openai/response.json'))))
        ->toStartWith('In a peaceful grove')
        ->and($extractor->reply(storedCall('anthropic', '/v1/messages', $this->loadMockResponse('claude/message.json'))))
        ->toStartWith('Here are some effective search strategies')
        ->and($extractor->reply(storedCall('google', '/v1beta/models/gemini-2.5-flash:generateContent', $this->loadMockResponse('google/response.json'))))
        ->toStartWith('At its core');
});

it('reads the accumulated text of a streamed reply', function () {
    $reply = app(MessageTextExtractor::class)->reply(storedCall('openai', '/v1/chat/completions', [
        'id' => 'chatcmpl-1',
        'content' => 'Streamed answer.',
        'streaming' => true,
    ]));

    expect($reply)->toBe('Streamed answer.');
});

it('has no reply when the response was not stored', function () {
    expect(app(MessageTextExtractor::class)->reply(storedCall('openai', '/v1/chat/completions', null)))->toBeNull();
});
