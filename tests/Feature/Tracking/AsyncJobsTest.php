<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Async jobs: the model is remembered from the submit request when the poll
 * can't name it, failed jobs keep the provider's error, and a JSON reply to a
 * `stream: true` request is not treated as a stream.
 */
it('remembers the model of a queued fal job when the result URL drops its sub-path', function () {
    $id = '764cabcf-b745-4b3e-ae38-1200304cf45b';

    Http::fake([
        'queue.fal.run/fal-ai/flux/dev' => Http::response($this->loadMockResponse('falai/queue-submit.json')),
        "queue.fal.run/fal-ai/flux/requests/{$id}" => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json')),
    ]);

    Http::post('https://queue.fal.run/fal-ai/flux/dev', ['prompt' => 'a sunset over mountains']);
    Http::get("https://queue.fal.run/fal-ai/flux/requests/{$id}");

    $row = SpectraRequest::sole();

    // 1024×768 → 1 MP, 1920×1080 → 2 MP (rounded up per image) = 3 MP × 2.5¢.
    expect($row->model)->toBe('FLUX.1 Dev')
        ->and($row->response_id)->toBe($id)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(7.5, 1e-9);
});

it('stores the provider error for a failed prediction', function () {
    Http::fake(['api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction-failed.json'))]);

    Http::get('https://api.replicate.com/v1/predictions/x1hfa7zq3hrmc0cs9qwbtyd0rg');

    $row = SpectraRequest::sole();

    expect($row->status_code)->toBe(200)
        ->and($row->error_message)->toBe('NSFW content detected. Try running it again, or try a different prompt.')
        // completed_at − started_at: 10:05:00.900 − 10:05:00.480.
        ->and($row->latency_ms)->toBe(420)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-9);
});

it('does not treat a JSON reply to a stream request as a stream', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1', 'stream' => true, 'messages' => []]);

    $row = SpectraRequest::sole();

    expect($row->is_streaming)->toBeFalse()
        ->and($row->prompt_tokens)->toBe(19)
        ->and($row->completion_tokens)->toBe(10);
});
