<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Spectra\Http\Middleware\EnforceBudgetLimit;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

function makeBudgetRequest(User $user, array $headers = []): Request
{
    $request = Request::create('/ai/generate', 'POST');
    $request->setUserResolver(fn () => $user);

    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return $request;
}

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Budget User',
        'email' => 'budget@example.com',
        'password' => 'password',
    ]);
});

it('allows requests when the budget is within limits', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->hardLimit()
        ->save();

    $response = app(EnforceBudgetLimit::class)->handle(
        makeBudgetRequest($this->user),
        fn () => response('ok')
    );

    expect($response->getStatusCode())->toBe(200);
});

it('returns a 429 json error when a hard limit is exceeded', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()
        ->forTrackable($this->user)
        ->withCost(150)
        ->create();

    $response = app(EnforceBudgetLimit::class)->handle(
        makeBudgetRequest($this->user, ['Accept' => 'application/json']),
        fn () => response('ok')
    );

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toContain('json');

    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['error'])->toBe('budget_exceeded')
        ->and($payload['limit_type'])->toBe('daily')
        ->and($payload['limit'])->toBe(100);
});

it('passes through unauthenticated requests', function () {
    $request = Request::create('/ai/generate', 'POST');
    $request->setUserResolver(fn () => null);

    $response = app(EnforceBudgetLimit::class)->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('passes through when budget enforcement is disabled', function () {
    config()->set('spectra.budget.enabled', false);

    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()
        ->forTrackable($this->user)
        ->withCost(500)
        ->create();

    $response = app(EnforceBudgetLimit::class)->handle(
        makeBudgetRequest($this->user),
        fn () => response('ok')
    );

    expect($response->getStatusCode())->toBe(200);
});
