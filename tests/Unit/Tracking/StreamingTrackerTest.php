<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Spectra as SpectraManager;

/**
 * StreamingTracker reads text, usage and timing from each provider's stream
 * chunks and records the request when the stream finishes.
 */
beforeEach(function () {
    Spectra::fake();
});

it('yields the text of OpenAI Chat Completions chunks', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['choices' => [['delta' => ['content' => 'Hello']]]],
        ['choices' => [['delta' => ['content' => ' world']]]],
        ['choices' => [['delta' => ['content' => '!']]]],
        ['choices' => [['finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    expect($content)->toBe('Hello world!')
        ->and($tracker->getContent())->toBe('Hello world!');
});

it('reads the usage, cached tokens included, from the final OpenAI chunk', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['choices' => [['delta' => ['content' => 'Hi']]]],
        ['choices' => [['finish_reason' => 'stop']], 'usage' => [
            'prompt_tokens' => 15,
            'completion_tokens' => 5,
            'prompt_tokens_details' => ['cached_tokens' => 3],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $usage = $tracker->getUsage();

    expect($usage['prompt_tokens'])->toBe(15)
        ->and($usage['completion_tokens'])->toBe(5)
        ->and($usage['cached_tokens'])->toBe(3);
});

it('reads text and usage from OpenAI Responses API events', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
        ['type' => 'response.output_text.delta', 'delta' => ' there'],
        ['type' => 'response.completed', 'response' => [
            'model' => 'gpt-4o-2024-11-20',
            'status' => 'completed',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    expect($content)->toBe('Hello there')
        ->and($tracker->getUsage()['prompt_tokens'])->toBe(10)
        ->and($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('joins appended content and records the time to first token', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $tracker->appendContent('Hello');
    $tracker->appendContent(' world');

    expect($tracker->getContent())->toBe('Hello world')
        ->and($tracker->getTimeToFirstToken())->not->toBeNull();
});

it('reads text and usage from Anthropic stream events', function () {
    $tracker = Spectra::stream('anthropic', 'claude-3-sonnet');

    $chunks = [
        ['type' => 'message_start', 'message' => ['model' => 'claude-3-sonnet', 'usage' => ['input_tokens' => 20]]],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => '!']],
        ['type' => 'message_delta', 'usage' => ['output_tokens' => 2]],
        ['type' => 'message_stop'],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    expect($content)->toBe('Hello!')
        ->and($tracker->getUsage()['prompt_tokens'])->toBe(20)
        ->and($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('reads text and usage from Gemini stream chunks', function () {
    $tracker = Spectra::stream('google', 'gemini-1.5-flash');

    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hi']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => ' there']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    expect($content)->toBe('Hi there')
        ->and($tracker->getUsage()['prompt_tokens'])->toBe(10)
        ->and($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('records a Gemini stream with inline image data as an image request', function () {
    $tracker = Spectra::stream('google', 'gemini-2.0-flash', [
        'endpoint' => '/v1/models/gemini-2.0-flash:generateContent',
    ]);

    $chunks = [[
        'candidates' => [[
            'content' => ['parts' => [[
                'inlineData' => [
                    'mimeType' => 'image/png',
                    'data' => base64_encode('fake-image'),
                ],
            ]]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 0,
        ],
    ]];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->modelType === 'image';
    });
});

it('reads text and usage from OpenAI SDK Responses API events', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    // OpenAI PHP SDK toArray() returns ['event' => '...', 'data' => [...]]
    // instead of the raw SSE format ['type' => '...', ...]
    $chunks = [
        ['event' => 'response.output_text.delta', 'data' => ['delta' => 'Hello']],
        ['event' => 'response.output_text.delta', 'data' => ['delta' => ' there']],
        ['event' => 'response.completed', 'data' => [
            'object' => 'response',
            'model' => 'gpt-4o-2024-11-20',
            'status' => 'completed',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    expect($content)->toBe('Hello there')
        ->and($tracker->getUsage()['prompt_tokens'])->toBe(10)
        ->and($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('counts the image of an OpenAI SDK image generation stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-image-1');

    $chunks = [
        ['event' => 'response.image_generation_call.partial_image', 'data' => ['partial_image_b64' => 'AAAA']],
        ['event' => 'response.completed', 'data' => [
            'object' => 'response',
            'model' => 'gpt-image-1',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'image_generation_call',
                    'result' => base64_encode('fake-png-data'),
                ],
            ],
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 0,
                'input_tokens_details' => ['cached_tokens' => 5],
            ],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertRequestCount(1);
    Spectra::assertTracked(function ($r) {
        return $r['context']->imageCount === 1
            && $r['context']->modelType === 'image';
    });
});

it('counts the image of a raw Responses API image generation stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-image-1');

    $chunks = [
        ['type' => 'response.image_generation_call.partial_image', 'partial_image_b64' => 'AAAA'],
        ['type' => 'response.image_generation_call.partial_image', 'partial_image_b64' => 'AAAA'],
        ['type' => 'response.completed', 'response' => [
            'id' => 'resp_img_123',
            'object' => 'response',
            'model' => 'gpt-image-1',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'image_generation_call',
                    'result' => base64_encode('fake-png-data'),
                ],
            ],
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 0,
                'input_tokens_details' => ['cached_tokens' => 5],
            ],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume — image streams yield no text
    }

    $tracker->finish();

    Spectra::assertRequestCount(1);
    Spectra::assertTracked(function ($r) {
        return $r['context']->imageCount === 1
            && $r['context']->modelType === 'image';
    });
});

it('records the response id of an OpenAI Chat Completions stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['id' => 'chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT', 'choices' => [['delta' => ['content' => 'Hi']]]],
        ['id' => 'chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT', 'choices' => [['finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->responseId === 'chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT';
    });
});

it('records the response id of an OpenAI Responses API stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
        ['type' => 'response.completed', 'response' => [
            'id' => 'resp_67ccd2bed1ec8190b14f964abc0542670bb6a6b452d3795b',
            'model' => 'gpt-4o-2024-11-20',
            'status' => 'completed',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->responseId === 'resp_67ccd2bed1ec8190b14f964abc0542670bb6a6b452d3795b';
    });
});

it('records the response id of an Anthropic stream', function () {
    $tracker = Spectra::stream('anthropic', 'claude-3-sonnet');

    $chunks = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_01HCDu5LRGeP2o7s2xGmxyx8', 'model' => 'claude-3-sonnet', 'usage' => ['input_tokens' => 20]]],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'message_delta', 'usage' => ['output_tokens' => 1]],
        ['type' => 'message_stop'],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->responseId === 'msg_01HCDu5LRGeP2o7s2xGmxyx8';
    });
});

it('takes over the pending context the Guzzle middleware left for the stream', function () {
    $manager = app(SpectraManager::class);
    $pendingContext = $manager->startRequest('openai', 'gpt-4o', [
        'endpoint' => '/v1/chat/completions',
    ]);
    $pendingContext->endpoint = '/v1/chat/completions';
    $pendingContext->requestData = ['model' => 'gpt-4o', 'stream' => true];
    $manager->setPendingStreamContext($pendingContext);

    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['id' => 'chatcmpl-abc', 'choices' => [['delta' => ['content' => 'Hi']]]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->endpoint === '/v1/chat/completions'
            && $r['context']->requestData === ['model' => 'gpt-4o', 'stream' => true];
    });
});

it('records the reasoning tokens of an OpenAI Chat Completions stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['choices' => [['delta' => ['content' => 'Think...']]]],
        ['choices' => [['finish_reason' => 'stop']], 'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 50,
            'completion_tokens_details' => ['reasoning_tokens' => 30],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    expect($tracker->getUsage()['reasoning_tokens'])->toBe(30);
    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 30;
    });
});

it('records the reasoning tokens of an OpenAI Responses API stream', function () {
    $tracker = Spectra::stream('openai', 'o3-mini');

    $chunks = [
        ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
        ['type' => 'response.completed', 'response' => [
            'model' => 'o3-mini-2024-01-20',
            'status' => 'completed',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 20,
                'output_tokens_details' => ['reasoning_tokens' => 15],
            ],
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    expect($tracker->getUsage()['reasoning_tokens'])->toBe(15);
    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 15;
    });
});

it('records the reasoning tokens of a Gemini stream', function () {
    $tracker = Spectra::stream('google', 'gemini-2.0-flash-thinking');

    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Reasoning...']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => ' done']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 100]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    expect($tracker->getUsage()['reasoning_tokens'])->toBe(100);
    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 100;
    });
});

it('flags tool calls from an OpenAI tool_calls finish reason', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['choices' => [['delta' => ['content' => '']]]],
        ['choices' => [['finish_reason' => 'tool_calls']], 'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->hasToolCalls === true
            && $r['context']->finishReason === 'tool_calls';
    });
});

it('flags tool calls from an Anthropic tool_use stop reason', function () {
    $tracker = Spectra::stream('anthropic', 'claude-3-sonnet');

    $chunks = [
        ['type' => 'message_start', 'message' => ['model' => 'claude-3-sonnet', 'usage' => ['input_tokens' => 20]]],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Using tool']],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->hasToolCalls === true
            && $r['context']->finishReason === 'tool_use';
    });
});

it('reports the usage set by hand, reasoning tokens included', function () {
    $tracker = Spectra::stream('openai', 'o3-mini');

    $tracker->setUsage([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cached_tokens' => 25,
        'reasoning_tokens' => 30,
    ]);

    $usage = $tracker->getUsage();

    expect($usage['prompt_tokens'])->toBe(100)
        ->and($usage['completion_tokens'])->toBe(50)
        ->and($usage['cached_tokens'])->toBe(25)
        ->and($usage['reasoning_tokens'])->toBe(30);
});

it('records nothing for an empty stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    foreach ($tracker->track([]) as $text) {
        // consume nothing
    }

    $tracker->finish();

    Spectra::assertRequestCount(0);
});

it('does not leak a pending stream context to the next tracker when a stream dies early', function () {
    $manager = app(SpectraManager::class);
    $pendingContext = $manager->startRequest('openai', 'gpt-4o', [
        'endpoint' => '/v1/chat/completions',
    ]);
    $manager->setPendingStreamContext($pendingContext);

    $failing = Spectra::stream();
    $brokenStream = (function () {
        throw new RuntimeException('connection dropped');
        yield 'never';
    })();

    try {
        foreach ($failing->track($brokenStream) as $text) {
            // consume
        }
    } catch (RuntimeException) {
    }

    // A later, unrelated stream must not inherit the dead stream's context.
    $tracker = Spectra::stream('anthropic', 'claude-sonnet-4-5');
    $chunks = [
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    expect($tracker->getContext()->provider)->toBe('anthropic')
        ->and($tracker->getContext()->endpoint)->not->toBe('/v1/chat/completions');
});
