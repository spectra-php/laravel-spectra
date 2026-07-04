<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra as SpectraManager;

it('records scaleway chat response to database', function () {
    $response = $this->loadMockResponse('scaleway/chat.json');

    Spectra::track('scaleway', 'llama-3.3-70b-instruct', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('scaleway')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(30)
        ->and($record->completion_tokens)->toBe(18)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

it('records scaleway rerank requests through the parametric endpoint', function () {
    $manager = app(SpectraManager::class);

    $context = $manager->startRequest('scaleway', 'rerank-model', [
        'endpoint' => '/my-project-id/v1/rerank',
        'request_data' => ['model' => 'rerank-model'],
    ]);

    $manager->recordSuccess($context, $this->loadMockResponse('scaleway/rerank.json'));

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('scaleway')
        ->and($record->prompt_tokens)->toBe(64);
});
