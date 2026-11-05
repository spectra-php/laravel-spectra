<?php

declare(strict_types=1);

/**
 * Spectra::forConversation(): every call tracked after it, however it is
 * tracked, belongs to the conversation and turn, until a call names its own.
 */

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

it('puts calls tracked by the HTTP client and by track() in the conversation and turn', function () {
    $completion = $this->loadMockResponse('openai/completion.json');
    Http::fake(['api.openai.com/*' => fn () => Http::response([...$completion, 'id' => 'chatcmpl-'.bin2hex(random_bytes(6))])]);

    Spectra::forConversation('chat_42', turn: 3);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Hi']]]]);

    expect(SpectraRequest::query()->get(['conversation_id', 'conversation_turn'])->map->only(['conversation_id', 'conversation_turn'])->all())
        ->toBe([
            ['conversation_id' => 'chat_42', 'conversation_turn' => 3],
            ['conversation_id' => 'chat_42', 'conversation_turn' => 3],
        ]);
});

it('takes a model\'s key, and lets a call name its own conversation', function () {
    $user = User::query()->create(['name' => 'Ava', 'email' => 'ava@example.test', 'password' => 'secret']);
    Spectra::forConversation($user, turn: 1);

    $own = Spectra::startRequest('openai', 'gpt-4o', ['conversation_id' => 'other']);
    $context = Spectra::startRequest('openai', 'gpt-4o', []);
    $context->inConversation('third', 9);

    expect(Spectra::startRequest('openai', 'gpt-4o')->conversationId)->toBe((string) $user->id)
        ->and([$own->conversationId, $own->conversationTurn])->toBe(['other', null])
        ->and([$context->conversationId, $context->conversationTurn])->toBe(['third', 9])
        ->and(fn () => Spectra::forConversation(' '))->toThrow(InvalidArgumentException::class);
});

it('forgets the conversation with the other globals', function () {
    Spectra::forConversation('chat_42', turn: 2);
    Spectra::clearGlobals();

    expect(Spectra::startRequest('openai', 'gpt-4o')->conversationId)->toBeNull();
});

it('applies the conversation in the fake', function () {
    Spectra::fake();
    Spectra::forConversation('chat_7', turn: 4);

    expect(Spectra::startRequest('openai', 'gpt-4o')->conversationTurn)->toBe(4);
});
