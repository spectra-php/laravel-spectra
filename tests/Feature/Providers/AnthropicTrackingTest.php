<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\Anthropic\Anthropic;
use Spectra\Providers\Anthropic\Handlers\MessageHandler;

/**
 * Anthropic responses recorded through Spectra::track(), where the handler
 * is picked by the response shape, and what the message handler reads from
 * the API's content blocks and from the flat-content shape SDKs return.
 */
it('records a tracked anthropic message with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('claude/message.json');

    Spectra::track('anthropic', 'claude-sonnet-4-5-20250929', fn () => $response);

    $record = SpectraRequest::sole();

    // Sonnet 4.5 standard (in 300, out 1500): (21×300 + 305×1500) / 1M = 0.4638¢
    expect($record->provider)->toBe('anthropic')
        ->and($record->model)->toBe('Claude Sonnet 4.5')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(21)
        ->and($record->completion_tokens)->toBe(305)
        ->and($record->finish_reason)->toBe('end_turn')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.4638, 1e-9);
});

it('reads the reply text of an anthropic message from its content blocks', function () {
    expect((new Anthropic)->extractResponse($this->loadMockResponse('claude/message.json')))->toContain('renewable energy');
});

it('reads tokens, model, stop reason and text from a flat-content response', function () {
    $response = $this->loadMockResponse('claude/claude-opus-4-6.json');
    $provider = new Anthropic;

    $metrics = $provider->extractMetrics($response);

    expect($metrics->tokens->promptTokens)->toBe(44)
        ->and($metrics->tokens->completionTokens)->toBe(70)
        ->and($provider->extractModel($response))->toBe('claude-opus-4-6')
        ->and($provider->extractFinishReason($response))->toBe('end_turn')
        ->and($provider->extractResponse($response))->toContain('quantum');
});

it('matches both the content-block and the flat-content response shapes', function () {
    $handler = new MessageHandler;

    expect($handler->matchesResponse([
        'type' => 'message',
        'content' => [['type' => 'text', 'text' => 'Hello']],
        'stop_reason' => 'end_turn',
    ]))->toBeTrue()
        ->and($handler->matchesResponse([
            'type' => 'text',
            'content' => 'Hello',
            'stop_reason' => 'end_turn',
        ]))->toBeTrue();
});
