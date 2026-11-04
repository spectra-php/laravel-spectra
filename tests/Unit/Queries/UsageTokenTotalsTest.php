<?php

declare(strict_types=1);

use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\UsageQueryBuilder;
use Workbench\App\Models\User;

/**
 * The public usage API sums tokens in every total and breakdown. The sum
 * must not be read through SpectraRequest's own `total_tokens` accessor,
 * which adds the row's prompt and completion tokens and found none on an
 * aggregate row, so every breakdown reported 0.
 */
it('sums tokens in the usage builder total and its provider, model and daily breakdowns', function () {
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'prompt_tokens' => 100, 'completion_tokens' => 20]);
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'prompt_tokens' => 30, 'completion_tokens' => 5]);

    expect((new UsageQueryBuilder)->stats()->totalTokens)->toBe(155)
        ->and((new UsageQueryBuilder)->byProvider()->first()['total_tokens'])->toBe(155)
        ->and((new UsageQueryBuilder)->byModel()->first()['total_tokens'])->toBe(155)
        ->and((new UsageQueryBuilder)->dailyUsage()->first()['total_tokens'])->toBe(155);
});

it('sums tokens in a trackable model usage total and its provider and model breakdowns', function () {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $owner = ['trackable_type' => $user->getMorphClass(), 'trackable_id' => (string) $user->getKey()];
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'prompt_tokens' => 100, 'completion_tokens' => 20, ...$owner]);
    SpectraRequest::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o', 'prompt_tokens' => 30, 'completion_tokens' => 5, ...$owner]);

    $start = now()->subDay();
    $end = now()->addDay();

    expect($user->getAiUsageForPeriod($start, $end)->totalTokens)->toBe(155)
        ->and($user->getAiUsageByProvider($start, $end)['openai']['total_tokens'])->toBe(155)
        ->and($user->getAiUsageByModel($start, $end)[0]['total_tokens'])->toBe(155);
});
