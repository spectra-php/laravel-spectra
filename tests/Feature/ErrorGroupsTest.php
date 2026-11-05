<?php

declare(strict_types=1);

/**
 * Error grouping: each failed call is saved with a fingerprint of its
 * provider, status, error type and message without the parts that change
 * between calls, the Errors screen lists the groups, the requests list
 * filters by one, and spectra:group-errors groups failures saved before the
 * migration ran.
 */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spectra\Actions\Errors\GetErrorGroups;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Errors\ErrorFingerprint;
use Spectra\Support\Tracking\ProviderErrorException;
use Spectra\Support\Tracking\StreamErrorException;

/**
 * Track one call that fails with this status and message.
 */
function trackFailure(string $message, int $status = 429, string $model = 'gpt-4o', string $provider = 'openai'): SpectraRequest
{
    $context = Spectra::startRequest($provider, $model);

    return Spectra::recordFailure($context, new ProviderErrorException($message, $status), $status);
}

it('groups messages that differ only in ids, numbers, urls, emails and masked keys', function (string $first, string $second) {
    $fingerprint = app(ErrorFingerprint::class);

    expect($fingerprint->for('openai', 429, null, $first))->toBe($fingerprint->for('openai', 429, null, $second));
})->with([
    'retry delay' => ['Rate limit reached. Please try again in 2s.', 'Rate limit reached. Please try again in 14.5s.'],
    'org and usage' => ['Rate limit reached for gpt-4o in organization org-3kTq9xYzAb on tokens per min (TPM): Limit 30000, Used 29871, Requested 2203.', 'Rate limit reached for gpt-4o in organization org-88aaZZ11bb on tokens per min (TPM): Limit 30000, Used 25012, Requested 950.'],
    'request id' => ['(Please include the request ID req_8f3a0c1d2e4b5a6978 in your message.)', '(Please include the request ID req_00ffaa11bb22cc33dd in your message.)'],
    'uuid' => ['Job 0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f failed', 'Job 0192f7c4-1111-7222-9333-444455556666 failed'],
    'url' => ['See https://example.test/a?id=1', 'See https://example.test/b'],
    'masked key' => ['Incorrect API key provided: sk-proj-****abcd.', 'Incorrect API key provided: sk-proj-****9xQz.'],
    'redacted email' => ['No account for ada@example.com', 'No account for [REDACTED:email]'],
]);

it('groups the same failure across the models it names', function () {
    $fingerprint = app(ErrorFingerprint::class);

    expect($fingerprint->for('openai', 429, null, 'Rate limit reached for gpt-4o-mini on tokens per min.', ['gpt-4o-mini', 'GPT-4o Mini']))
        ->toBe($fingerprint->for('openai', 429, null, 'Rate limit reached for gpt-5.5 on tokens per min.', ['gpt-5.5', 'GPT-5.5']));
});

it('keeps different causes apart', function () {
    $fingerprint = app(ErrorFingerprint::class);
    $message = 'The server had an error while processing your request.';

    expect(collect([
        $fingerprint->for('openai', 500, null, $message),
        $fingerprint->for('openai', 502, null, $message),
        $fingerprint->for('anthropic', 500, null, $message),
        $fingerprint->for('openai', 500, StreamErrorException::class, $message),
        $fingerprint->for('openai', 500, null, 'Rate limit reached.'),
    ])->unique())->toHaveCount(5);
});

it('saves a fingerprint on failed calls only', function () {
    $failed = trackFailure('Rate limit reached. Please try again in 2s.');
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Hi']]]]);

    expect($failed->fresh()->error_fingerprint)->toHaveLength(16)
        ->and(SpectraRequest::query()->whereNull('error_fingerprint')->count())->toBe(1);
});

it('lists the groups with the most failures first, with their models, latest message and trend', function () {
    $this->travelTo('2026-11-04 12:00:00');

    trackFailure('Rate limit reached. Please try again in 2s.', model: 'gpt-4o');
    $this->travelTo('2026-11-04 12:05:00');
    trackFailure('Rate limit reached. Please try again in 9s.', model: 'gpt-4o-mini');
    $latest = trackFailure('Rate limit reached. Please try again in 1s.', model: 'gpt-4o');
    trackFailure('The server had an error.', 500);
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Hi']]]]);

    $response = app(GetErrorGroups::class)(DateRange::fromRequest(new Request(['period' => 'all'])));
    $rateLimits = $response->groups->first();

    expect($response->request_count)->toBe(5)
        ->and($response->failed_count)->toBe(4)
        ->and($response->error_rate)->toBe(0.8)
        ->and($response->ungrouped_count)->toBe(0)
        ->and($response->groups)->toHaveCount(2)
        ->and($rateLimits->failure_count)->toBe(3)
        ->and($rateLimits->share)->toBe(0.75)
        ->and($rateLimits->status_code)->toBe(429)
        ->and($rateLimits->latest_request_id)->toBe($latest->id)
        ->and($rateLimits->message)->toBe('Rate limit reached. Please try again in 1s.')
        ->and($rateLimits->models)->toBe([['model' => 'GPT-4o', 'count' => 2], ['model' => 'GPT-4o Mini', 'count' => 1]])
        ->and($rateLimits->trend)->toHaveCount(14)
        ->and(array_sum($rateLimits->trend))->toBe(3)
        ->and($rateLimits->trend[13])->toBe(3);
});

it('filters the requests list by error group', function () {
    $group = trackFailure('Rate limit reached. Please try again in 2s.');
    trackFailure('Rate limit reached. Please try again in 5s.');
    trackFailure('The server had an error.', 500);

    $list = app()->call([app(RequestsController::class), '__invoke'], ['request' => new Request(['period' => 'all', 'error_fingerprint' => $group->error_fingerprint])]);

    expect($list->toArray()['data'])->toHaveCount(2);
});

it('groups failures saved before the migration with spectra:group-errors', function () {
    trackFailure('Rate limit reached. Please try again in 2s.');
    trackFailure('Rate limit reached. Please try again in 7s.');
    SpectraRequest::query()->update(['error_fingerprint' => null]);

    $this->artisan('spectra:group-errors')->expectsOutputToContain('Grouped 2 failed calls')->assertSuccessful();

    expect(SpectraRequest::query()->distinct()->pluck('error_fingerprint')->all())->toHaveCount(1)
        ->and(SpectraRequest::query()->value('error_fingerprint'))->not->toBeNull();

    $this->artisan('spectra:group-errors')->expectsOutputToContain('grouped already')->assertSuccessful();
});

it('saves failures and says the migration is missing until it runs', function () {
    Schema::table('spectra_requests', function ($table) {
        $table->dropIndex('spectra_requests_error_fingerprint_index');
        $table->dropColumn('error_fingerprint');
    });
    Log::spy();

    trackFailure('Rate limit reached.');
    $response = app(GetErrorGroups::class)(DateRange::fromRequest(new Request(['period' => 'all'])));

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and($response->migrated)->toBeFalse()
        ->and($response->failed_count)->toBe(1)
        ->and($response->groups)->toBeEmpty();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'error_fingerprint'));
    $this->artisan('spectra:group-errors')->assertFailed();
});
