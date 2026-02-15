<?php

use Spectra\Facades\Spectra;
use Spectra\Support\Tracking\StreamingTracker;

beforeEach(function () {
    Spectra::fake();
});

it('creates a streaming tracker via facade', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    expect($tracker)->toBeInstanceOf(StreamingTracker::class);
});

it('extracts text from openai completions api chunks', function () {
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

    expect($content)->toBe('Hello world!');
    expect($tracker->getContent())->toBe('Hello world!');
});

it('extracts usage from final chunk', function () {
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
    expect($usage['prompt_tokens'])->toBe(15);
    expect($usage['completion_tokens'])->toBe(5);
    expect($usage['cached_tokens'])->toBe(3);
});

it('tracks time to first token', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['choices' => [['delta' => ['content' => 'Hello']]]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $ttft = $tracker->getTimeToFirstToken();
    expect($ttft)->not->toBeNull();
    expect($ttft)->toBeGreaterThanOrEqual(0);
});

it('handles openai responses api format', function () {
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

    expect($content)->toBe('Hello there');
    expect($tracker->getUsage()['prompt_tokens'])->toBe(10);
    expect($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('can manually set usage', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $tracker->setUsage([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cached_tokens' => 25,
    ]);

    $usage = $tracker->getUsage();
    expect($usage['prompt_tokens'])->toBe(100);
    expect($usage['completion_tokens'])->toBe(50);
    expect($usage['cached_tokens'])->toBe(25);
});

it('can manually append content', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $tracker->appendContent('Hello');
    $tracker->appendContent(' world');

    expect($tracker->getContent())->toBe('Hello world');
    expect($tracker->getTimeToFirstToken())->not->toBeNull();
});

it('records request on finish', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    $chunks = [
        ['id' => 'chatcmpl-abc123', 'choices' => [['delta' => ['content' => 'Test']]]],
        ['id' => 'chatcmpl-abc123', 'choices' => [['finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $result = $tracker->finish();

    Spectra::assertRequestCount(1);
    Spectra::assertProviderUsed('openai');
    Spectra::assertModelUsed('gpt-4o');
});

it('handles anthropic streaming format', function () {
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

    expect($content)->toBe('Hello!');
    expect($tracker->getUsage()['prompt_tokens'])->toBe(20);
    expect($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('handles google streaming format', function () {
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

    expect($content)->toBe('Hi there');
    expect($tracker->getUsage()['prompt_tokens'])->toBe(10);
    expect($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('resolves model type from google stream response shape on shared endpoints', function () {
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

it('handles openai sdk event format for responses api text', function () {
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

    expect($content)->toBe('Hello there');
    expect($tracker->getUsage()['prompt_tokens'])->toBe(10);
    expect($tracker->getUsage()['completion_tokens'])->toBe(2);
});

it('handles openai sdk event format for streaming image generation', function () {
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

    $result = $tracker->finish();

    Spectra::assertRequestCount(1);
    Spectra::assertTracked(function ($r) {
        return $r['context']->imageCount === 1
            && $r['context']->modelType === 'image';
    });
});

it('tracks streaming image generation from responses api', function () {
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

    $result = $tracker->finish();

    Spectra::assertRequestCount(1);
    Spectra::assertTracked(function ($r) {
        return $r['context']->imageCount === 1
            && $r['context']->modelType === 'image';
    });
});

it('captures response_id from openai completions api stream', function () {
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

it('captures response_id from openai responses api stream', function () {
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

it('captures response_id from anthropic stream', function () {
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

it('uses endpoint passed via options', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o', ['endpoint' => '/v1/chat/completions']);

    $chunks = [
        ['id' => 'chatcmpl-abc', 'choices' => [['delta' => ['content' => 'Hi']]]],
        ['id' => 'chatcmpl-abc', 'choices' => [['finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->endpoint === '/v1/chat/completions';
    });
});

it('reuses context from GuzzleMiddleware when available', function () {
    $manager = app(\Spectra\Spectra::class);
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

it('extracts reasoning tokens from openai completions api stream', function () {
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

    $usage = $tracker->getUsage();
    expect($usage['reasoning_tokens'])->toBe(30);

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 30;
    });
});

it('extracts reasoning tokens from openai responses api stream', function () {
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

    $usage = $tracker->getUsage();
    expect($usage['reasoning_tokens'])->toBe(15);

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 15;
    });
});

it('extracts reasoning tokens from google stream', function () {
    $tracker = Spectra::stream('google', 'gemini-2.0-flash-thinking');

    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Reasoning...']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => ' done']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 100]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    $usage = $tracker->getUsage();
    expect($usage['reasoning_tokens'])->toBe(100);

    $tracker->finish();

    Spectra::assertTracked(function ($r) {
        return $r['context']->reasoningTokens === 100;
    });
});

it('detects tool calls from finish_reason in streaming', function () {
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

it('detects anthropic tool_use finish reason in streaming', function () {
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

it('sets manual usage with reasoning tokens', function () {
    $tracker = Spectra::stream('openai', 'o3-mini');

    $tracker->setUsage([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cached_tokens' => 25,
        'reasoning_tokens' => 30,
    ]);

    $usage = $tracker->getUsage();
    expect($usage['prompt_tokens'])->toBe(100);
    expect($usage['completion_tokens'])->toBe(50);
    expect($usage['cached_tokens'])->toBe(25);
    expect($usage['reasoning_tokens'])->toBe(30);
});
