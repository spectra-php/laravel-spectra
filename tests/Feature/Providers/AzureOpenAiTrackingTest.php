<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\Support\ProviderRegistry;

/**
 * Azure OpenAI tracking through the HTTP client. Azure serves the OpenAI API
 * from its own resource hosts under /openai/deployments/{deployment}/... or
 * /openai/v1/... paths; requests are recorded as OpenAI with the path
 * normalized to the OpenAI /v1 endpoint. Costs are worked out by hand from
 * OpenAIPricing (cents per 1M tokens).
 */
it('records azure chat completions as openai on the v1 endpoint', function (string $url) {
    Http::fake(['*' => Http::response($this->loadMockResponse('openai/azure_chat_completion.json'))]);

    // Deployment requests name no model; it comes from the response.
    Http::post($url, ['messages' => [['role' => 'user', 'content' => 'When does the contract renew?']]]);

    $row = SpectraRequest::query()->sole();

    // gpt-4.1 standard: in 200, out 800. (1000*200 + 200*800) / 1M = 0.36
    expect($row->provider)->toBe('openai')
        ->and($row->endpoint)->toBe('/v1/chat/completions')
        ->and($row->model)->toBe('GPT-4.1')
        ->and($row->prompt_tokens)->toBe(1000)
        ->and($row->completion_tokens)->toBe(200)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.36, 1e-9);
})->with([
    'deployment on openai.azure.com' => ['https://contoso.openai.azure.com/openai/deployments/gpt-41-prod/chat/completions?api-version=2024-10-21'],
    'v1 api on services.ai.azure.com' => ['https://mspa-portal-resource.services.ai.azure.com/openai/v1/chat/completions'],
]);

it('records azure embeddings as openai embeddings with their cost', function () {
    Http::fake(['*' => Http::response($this->loadMockResponse('openai/embedding.json'))]);

    Http::post('https://contoso.openai.azure.com/openai/deployments/embeddings/embeddings?api-version=2024-10-21', ['input' => 'hello']);

    $row = SpectraRequest::query()->sole();

    // text-embedding-3-small: 5 tokens × 2 cents per 1M = 0.00001¢
    expect($row->provider)->toBe('openai')
        ->and($row->endpoint)->toBe('/v1/embeddings')
        ->and($row->model)->toBe('Text Embedding 3 Small')
        ->and($row->model_type)->toBe('embedding')
        ->and($row->prompt_tokens)->toBe(5)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.00001, 1e-12);
});

it('maps the azure responses preview api to the v1 responses endpoint', function () {
    Http::fake(['*' => Http::response($this->loadMockResponse('openai/response.json'))]);

    Http::post('https://contoso.openai.azure.com/openai/responses?api-version=2025-04-01-preview', ['model' => 'gpt-4.1', 'input' => 'Hi']);

    $row = SpectraRequest::query()->sole();

    expect($row->provider)->toBe('openai')
        ->and($row->endpoint)->toBe('/v1/responses')
        ->and($row->prompt_tokens)->toBe(36)
        ->and($row->completion_tokens)->toBe(87);
});

it('does not track non-usage azure endpoints', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => []])]);

    Http::get('https://contoso.openai.azure.com/openai/models?api-version=2024-10-21');
    Http::get('https://contoso.openai.azure.com/openai/deployments/gpt-41-prod/files');

    expect(SpectraRequest::query()->count())->toBe(0);
});

it('records requests to a custom azure domain configured through laravel-ai', function () {
    config([
        'ai.providers.openai.url' => 'https://api.openai.com/v1',
        'ai.providers.azure.url' => 'https://ai-gateway.contoso.com',
    ]);
    app()->forgetInstance(ProviderRegistry::class);

    Http::fake(['*' => Http::response($this->loadMockResponse('openai/azure_chat_completion.json'))]);

    Http::post('https://ai-gateway.contoso.com/openai/deployments/gpt-41-prod/chat/completions?api-version=2024-10-21', [
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $row = SpectraRequest::query()->sole();

    expect($row->provider)->toBe('openai')
        ->and($row->endpoint)->toBe('/v1/chat/completions')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.36, 1e-9);
});
