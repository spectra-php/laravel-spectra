<?php

declare(strict_types=1);

use Spectra\Support\Pricing\Feeds\LiteLlmFeed;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * LiteLlmFeed turns LiteLLM's cost map (USD per token, the tier and the
 * long-prompt threshold in the field name) into the rows spectra:pricing-sync
 * maps onto a provider's catalog.
 */
it('reads the tier and the long-prompt threshold from the field names', function () {
    $feed = new LiteLlmFeed(['gpt-x' => [
        'litellm_provider' => 'openai',
        'mode' => 'chat',
        'input_cost_per_token' => 1e-6,
        'output_cost_per_token' => 4e-6,
        'cache_read_input_token_cost' => 1e-7,
        'cache_creation_input_token_cost' => 1.25e-6,
        'cache_creation_input_token_cost_above_1hr' => 2e-6,
        'input_cost_per_token_batches' => 5e-7,
        'output_cost_per_token_batches' => 2e-6,
        'input_cost_per_token_above_272k_tokens_priority' => 4e-6,
        'output_cost_per_token_above_272k_tokens_priority' => 1.2e-5,
        // Not a price per token: not a row.
        'search_context_cost_per_query' => ['search_context_size_low' => 0.01],
        'max_input_tokens' => 1_000_000,
    ]]);

    $rows = collect($feed->rows(FakeCatalogs::openai()))
        ->map(fn (array $row) => array_diff_key($row, array_flip(['model_id', 'priced_per', 'unit', 'kind'])))
        ->all();

    // USD per token × 1,000,000 = USD per 1M tokens.
    expect($rows)->toBe([
        ['tier' => 'standard', 'above_input_tokens' => null, 'input' => 1.0, 'output' => 4.0, 'cached_input' => 0.1, 'cache_write_5m' => 1.25, 'cache_write_1h' => 2.0],
        ['tier' => 'batch', 'above_input_tokens' => null, 'input' => 0.5, 'output' => 2.0],
        ['tier' => 'priority', 'above_input_tokens' => 272_000, 'input' => 4.0, 'output' => 12.0],
    ]);
});

it('strips the provider prefix and skips other hosts, unpriced models and repeated modality prices', function () {
    $feed = new LiteLlmFeed([
        'openai/gpt-y' => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => 3e-7, 'output_cost_per_token' => 1.2e-6, 'input_cost_per_audio_token' => 3e-7, 'output_cost_per_audio_token' => 2e-6],
        'azure/gpt-y' => ['litellm_provider' => 'azure', 'mode' => 'chat', 'input_cost_per_token' => 9e-7, 'output_cost_per_token' => 9e-6],
        'gpt-free' => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => 0, 'output_cost_per_token' => 0],
        // A new model that isn't billed per token can't be added from the feed.
        'image-new' => ['litellm_provider' => 'openai', 'mode' => 'image_generation', 'output_cost_per_image' => 0.04],
        'sample_spec' => ['litellm_provider' => 'one of: openai, anthropic'],
    ]);

    $rows = $feed->rows(FakeCatalogs::openai());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['model_id'])->toBe('gpt-y')
        ->and($rows[0])->not->toHaveKey('audio_input')
        ->and($rows[0]['audio_output'])->toBe(2.0);
});

it('has no rows for a provider LiteLLM is not the feed for', function () {
    $feed = new LiteLlmFeed(['claude-x' => ['litellm_provider' => 'scaleway', 'mode' => 'chat', 'input_cost_per_token' => 3e-6, 'output_cost_per_token' => 1.5e-5]]);

    expect($feed->rows(FakeCatalogs::acme()))->toBe([])
        ->and(LiteLlmFeed::covers('scaleway'))->toBeFalse();
});
