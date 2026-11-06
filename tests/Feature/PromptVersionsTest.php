<?php

declare(strict_types=1);

/**
 * Prompt versioning: calls tagged with a prompt template and version (by
 * Spectra::withPrompt(), the track() options or the context), compared per
 * version on the Prompts screen, filtered in the requests list, and saved
 * without the columns until the prompt migration runs.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\PromptsController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Models\SpectraRequest;

/**
 * Track one call, with the given track() options.
 */
function trackPromptCall(array $options = [], int $status = 200): void
{
    Spectra::track('openai', 'gpt-4o', function ($context) use ($status) {
        $context->httpStatus = $status;

        return ['choices' => [['message' => ['content' => 'Hi']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
    }, $options);
}

it('tags every call inside withPrompt(), and takes the version from the options or the context', function () {
    Spectra::withPrompt('support-answer', 'v3', function () {
        trackPromptCall();
        trackPromptCall(['prompt_version' => 'v4-beta']);
    });

    Spectra::track('openai', 'gpt-4o', function ($context) {
        $context->usingPrompt('summarize', '2026-11');

        return ['choices' => [['message' => ['content' => 'Hi']]]];
    });

    trackPromptCall();

    expect(SpectraRequest::query()->oldest()->get(['prompt_name', 'prompt_version'])->map->only(['prompt_name', 'prompt_version'])->all())
        ->toEqualCanonicalizing([
            ['prompt_name' => 'support-answer', 'prompt_version' => 'v3'],
            ['prompt_name' => 'support-answer', 'prompt_version' => 'v4-beta'],
            ['prompt_name' => 'summarize', 'prompt_version' => '2026-11'],
            ['prompt_name' => null, 'prompt_version' => null],
        ]);
});

it('compares versions on calls, errors, cost, tokens and scores', function () {
    Spectra::withPrompt('support-answer', 'v3', function () {
        trackPromptCall();
        trackPromptCall();
        trackPromptCall(status: 500);
        Spectra::score('helpful', true);
    });
    // The next version runs in its own request, so its own trace.
    Spectra::newTrace();
    Spectra::withPrompt('support-answer', 'v4', fn () => trackPromptCall());

    $versions = collect(app()->call([app(PromptsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray()['versions'])
        ->map(fn ($row) => is_array($row) ? $row : $row->toArray())
        ->keyBy('prompt_version');

    expect($versions['v3'])->toMatchArray([
        'prompt_name' => 'support-answer',
        'request_count' => 3,
        'failed_count' => 1,
        'avg_tokens' => 120,
    ])->and($versions['v3']['error_rate'])->toEqualWithDelta(0.3333, 0.0001)
        ->and($versions['v3']['scores'])->toBe([['name' => 'helpful', 'type' => 'boolean', 'count' => 1, 'average' => 1.0]])
        ->and($versions['v4']['request_count'])->toBe(1)
        ->and($versions['v4']['scores'])->toBe([]);
});

it('filters the requests list by prompt and version', function () {
    Spectra::withPrompt('support-answer', 'v3', fn () => trackPromptCall());
    Spectra::withPrompt('support-answer', 'v4', fn () => trackPromptCall());

    $list = app()->call([app(RequestsController::class), '__invoke'], ['request' => new Request(['period' => 'all', 'prompt_name' => 'support-answer', 'prompt_version' => 'v4'])]);
    $rows = collect($list->toArray()['data'] ?? $list->toArray()['requests']['data'] ?? []);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['prompt_version'] ?? SpectraRequest::query()->find($rows->first()['id'])->prompt_version)->toBe('v4');
});

it('saves calls without the prompt columns, keeping the trace columns, until the migration runs', function () {
    Schema::table('spectra_requests', function ($table) {
        $table->dropIndex('spectra_requests_prompt_index');
        $table->dropColumn(['prompt_name', 'prompt_version']);
    });
    Log::spy();

    Spectra::withPrompt('support-answer', 'v3', function () {
        trackPromptCall();
        trackPromptCall();
    });

    expect(SpectraRequest::query()->count())->toBe(2)
        ->and(SpectraRequest::query()->whereNull('started_at')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'prompt_name, prompt_version'));
});

it('lists no versions and says the migration is missing until it runs', function () {
    Schema::table('spectra_requests', function ($table) {
        $table->dropIndex('spectra_requests_prompt_index');
        $table->dropColumn(['prompt_name', 'prompt_version']);
    });
    Log::spy();

    // SQLite reads the missing quoted column as a string, so an unguarded
    // query would list these calls under a "prompt_name" prompt.
    trackPromptCall();
    trackPromptCall();

    $response = app()->call([app(PromptsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect($response['migrated'])->toBeFalse()
        ->and($response['versions'])->toBeEmpty();
});
