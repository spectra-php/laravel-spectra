<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Http\Controllers\Api\ConversationsController;
use Spectra\Http\Controllers\Api\ShowConversationController;
use Spectra\Models\SpectraRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The conversations list and transcript endpoints: totals and turns per
 * conversation, filters that keep whole conversations, and the transcript's
 * turns with their text and the cost so far.
 */
function callConversations(array $query = []): array
{
    return app()->call([app(ConversationsController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray();
}

function callConversation(string $conversationId): array
{
    return app()->call([app(ShowConversationController::class), '__invoke'], ['conversationId' => $conversationId])->toArray();
}

/**
 * A chat call of the support conversation, started at the given second.
 *
 * @param  array<string, mixed>  $attributes
 */
function supportCall(int $second, ?int $turn, array $attributes = []): SpectraRequest
{
    $start = Date::parse('2026-11-03 10:00:00')->addSeconds($second);

    return SpectraRequest::factory()->create([
        'conversation_id' => 'support-42',
        'conversation_turn' => $turn,
        'endpoint' => '/v1/chat/completions',
        'model_type' => 'text',
        'started_at' => $start,
        'created_at' => $start,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function chatRequest(string $userMessage): array
{
    return ['messages' => [['role' => 'user', 'content' => $userMessage]]];
}

/**
 * @return array<string, mixed>
 */
function chatReply(string $text): array
{
    return ['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
}

it('lists one row of totals per conversation, counting turns', function () {
    supportCall(0, 1, ['total_cost_in_cents' => 3, 'prompt_tokens' => 100]);
    // An agent's tool loop: two more calls in turn 2.
    supportCall(10, 2, ['total_cost_in_cents' => 4]);
    supportCall(12, 2, ['total_cost_in_cents' => 5, 'status_code' => 500]);
    supportCall(30, null, ['total_cost_in_cents' => 2]);
    SpectraRequest::factory()->create(['conversation_id' => null]);

    $json = callConversations();
    $conversation = $json['data'][0];

    expect($json['total'])->toBe(1)
        ->and($conversation['conversation_id'])->toBe('support-42')
        ->and($conversation['request_count'])->toBe(4)
        // Turns 1 and 2, plus the call without a turn number.
        ->and($conversation['turn_count'])->toBe(3)
        ->and($conversation['failed_count'])->toBe(1)
        ->and($conversation['total_cost_in_cents'])->toEqualWithDelta(9.0, 0.0001)
        ->and($conversation['started_at'])->toStartWith('2026-11-03T10:00:00.000')
        ->and($conversation['last_activity_at'])->toStartWith('2026-11-03T10:00:30.000');
});

it('keeps every call of a conversation in its totals when a filter matches one call', function () {
    supportCall(0, 1, ['model' => 'gpt-4o-mini', 'total_cost_in_cents' => 1]);
    supportCall(10, 2, ['model' => 'gpt-4o', 'total_cost_in_cents' => 6]);
    SpectraRequest::factory()->create(['conversation_id' => 'sales-7', 'model' => 'claude-x']);

    $json = callConversations(['model' => 'gpt-4o']);

    expect($json['total'])->toBe(1)
        ->and($json['data'][0]['request_count'])->toBe(2)
        ->and($json['data'][0]['total_cost_in_cents'])->toEqualWithDelta(7.0, 0.0001);
});

it('finds a conversation by part of its id and sorts by last activity', function () {
    supportCall(0, 1);
    SpectraRequest::factory()->create(['conversation_id' => 'sales-7', 'created_at' => Date::parse('2026-11-04 09:00:00')]);

    expect(collect(callConversations(['search' => 'sales'])['data'])->pluck('conversation_id')->all())->toBe(['sales-7'])
        ->and(collect(callConversations()['data'])->pluck('conversation_id')->all())->toBe(['sales-7', 'support-42']);
});

it('sums the summary over every matching conversation', function () {
    supportCall(0, 1, ['total_cost_in_cents' => 6]);
    supportCall(10, 2, ['status_code' => 429]);
    SpectraRequest::factory()->create(['conversation_id' => 'sales-7', 'total_cost_in_cents' => 2]);

    $summary = callConversations(['per_page' => 1])['summary'];

    expect($summary['conversation_count'])->toBe(2)
        ->and($summary['failed_conversation_count'])->toBe(1)
        ->and($summary['total_cost_in_cents'])->toEqualWithDelta(8.0, 0.0001)
        ->and($summary['avg_cost_in_cents'])->toEqualWithDelta(4.0, 0.0001)
        ->and($summary['avg_turn_count'])->toBe(1.5);
});

it('shows a conversation as turns with the prompt, the reply and the cost so far', function () {
    supportCall(0, 1, ['request' => chatRequest('Where is my order?'), 'response' => chatReply('It ships today.'), 'total_cost_in_cents' => 3, 'latency_ms' => 800]);
    // Turn 2 is a tool loop: the prompt is in its first call, the answer in its last.
    supportCall(10, 2, ['request' => chatRequest('Can I change the address?'), 'response' => chatReply(''), 'total_cost_in_cents' => 4, 'latency_ms' => 500]);
    supportCall(11, 2, ['model_type' => 'embedding', 'endpoint' => '/v1/embeddings', 'request' => ['input' => 'address change policy'], 'total_cost_in_cents' => 1, 'latency_ms' => 100]);
    supportCall(12, 2, ['request' => ['messages' => [['role' => 'tool', 'content' => '{"ok":true}']]], 'response' => chatReply('Yes, I updated it.'), 'total_cost_in_cents' => 5, 'latency_ms' => 1500]);
    supportCall(20, 3, ['status_code' => 500, 'total_cost_in_cents' => 9]);

    $conversation = callConversation('support-42');
    $turns = $conversation['turns'];

    expect($conversation['turn_count'])->toBe(3)
        ->and($conversation['stores_prompts'])->toBeTrue()
        ->and($turns)->toHaveCount(3)
        ->and($turns[0])->toMatchArray(['turn' => 1, 'prompt' => 'Where is my order?', 'reply' => 'It ships today.', 'running_cost_in_cents' => 3.0])
        ->and($turns[1])->toMatchArray(['turn' => 2, 'prompt' => 'Can I change the address?', 'reply' => 'Yes, I updated it.', 'request_count' => 3, 'total_cost_in_cents' => 10.0, 'running_cost_in_cents' => 13.0])
        // From +10 s to the last call ending at +12 s + 1.5 s.
        ->and($turns[1]['duration_ms'])->toBe(3500)
        // The failed call costs nothing, so the total stays.
        ->and($turns[2])->toMatchArray(['turn' => 3, 'prompt' => null, 'reply' => null, 'failed_count' => 1, 'running_cost_in_cents' => 13.0]);
});

it('takes the text from the streamed reply, not from the guardrails and rewriter around it', function () {
    $verdict = '{"toxic":false}';
    // An input guardrail, a query rewriter, the streamed agent reply, and an output guardrail.
    supportCall(0, 1, ['request' => [...chatRequest("<conversation>\nVisitor: Hello\n</conversation>\n<text>\nAre you okay?\n</text>"), 'response_format' => ['type' => 'json_schema']], 'response' => chatReply($verdict)]);
    supportCall(0, 1, ['request' => chatRequest("Chat:\nVisitor: Hello\n\nLast message: Are you okay?"), 'response' => chatReply('are you okay')]);
    supportCall(1, 1, ['request' => [...chatRequest('Are you okay?'), 'stream' => true], 'response' => ['streaming' => true, 'content' => "I'm doing well, thanks!"]]);
    supportCall(2, 1, ['request' => [...chatRequest("<text>\nI'm doing well, thanks!\n</text>"), 'response_format' => ['type' => 'json_schema']], 'response' => chatReply($verdict)]);

    expect(callConversation('support-42')['turns'][0])->toMatchArray(['prompt' => 'Are you okay?', 'reply' => "I'm doing well, thanks!", 'request_count' => 4]);
});

it('passes over structured-output calls when the turn has a plain one', function (array $structured) {
    supportCall(0, 1, ['request' => [...chatRequest('<text>Hi</text>'), ...$structured], 'response' => chatReply('{"ok":true}')]);
    supportCall(1, 1, ['request' => chatRequest('Hi'), 'response' => chatReply('Hello!')]);
    supportCall(2, 1, ['request' => [...chatRequest('<text>Hello!</text>'), ...$structured], 'response' => chatReply('{"ok":true}')]);

    expect(callConversation('support-42')['turns'][0])->toMatchArray(['prompt' => 'Hi', 'reply' => 'Hello!']);
})->with([
    'OpenAI chat' => [['response_format' => ['type' => 'json_object']]],
    'OpenAI Responses' => [['text' => ['format' => ['type' => 'json_schema']]]],
    'Anthropic' => [['output_config' => ['format' => ['type' => 'json_schema']]]],
    'Gemini' => [['generationConfig' => ['responseMimeType' => 'application/json']]],
    'Ollama' => [['format' => 'json']],
]);

it('still shows a turn made only of structured-output calls', function () {
    supportCall(0, 1, ['request' => [...chatRequest('Rate this answer'), 'response_format' => ['type' => 'json_schema']], 'response' => chatReply('{"score":4}')]);

    expect(callConversation('support-42')['turns'][0])->toMatchArray(['prompt' => 'Rate this answer', 'reply' => '{"score":4}']);
});

it('makes each call without a turn number a turn of its own', function () {
    supportCall(0, null);
    supportCall(5, null);

    expect(callConversation('support-42')['turns'])->toHaveCount(2);
});

it('answers 404 for a conversation with no calls', function () {
    callConversation('nobody-here');
})->throws(NotFoundHttpException::class);
