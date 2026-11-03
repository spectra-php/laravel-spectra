<?php

declare(strict_types=1);

use Spectra\Providers\Cohere\Handlers\ChatHandler;

/**
 * The Cohere chat handler: the response text it keeps, and the stream
 * events it reads text, usage and the model from.
 */
it('reads text, usage and the model from cohere stream events, skipping thinking deltas', function () {
    $stream = (new ChatHandler)->streamingHandler();

    expect($stream->text(['type' => 'content-delta', 'delta' => ['message' => ['content' => ['text' => 'Hi']]]]))->toBe('Hi')
        ->and($stream->text(['type' => 'content-delta', 'delta' => ['message' => ['content' => ['thinking' => 'hmm']]]]))->toBeNull()
        ->and($stream->usage(['type' => 'message-end', 'delta' => ['usage' => ['billed_units' => ['input_tokens' => 5, 'output_tokens' => 26]]]], [])['completion_tokens'])->toBe(26)
        ->and($stream->model(['type' => 'message-start', 'id' => 'abc']))->toBe(['id' => 'abc']);
});

it('extracts response text from cohere response', function () {
    $response = $this->loadMockResponse('cohere/chat.json');

    $content = (new ChatHandler)->extractResponse($response);

    expect($content)->toContain('Command R');
});
