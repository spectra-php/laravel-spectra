<?php

declare(strict_types=1);

/**
 * Alerts: rules from spectra.alerts.rules checked by spectra:check-alerts.
 * A broken rule fires once, again after its cooldown, and resolves when it
 * recovers, notifying email, Slack and a webhook; the dashboard shows each
 * rule's value now.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spectra\Events\AlertResolved;
use Spectra\Events\AlertTriggered;
use Spectra\Http\Controllers\Api\AlertsController;
use Spectra\Mail\AlertMail;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Alerts\AlertRule;

beforeEach(function () {
    $this->freezeTime();
    Http::fake();
    Mail::fake();
    Event::fake([AlertTriggered::class, AlertResolved::class]);

    config()->set('spectra.alerts.rules', [
        ['name' => 'High error rate', 'metric' => 'error_rate', 'above' => 0.2, 'window' => 15, 'min_requests' => 5],
    ]);
    config()->set('spectra.alerts.cooldown', 60);
    config()->set('spectra.alerts.notify', [
        'mail' => 'ops@example.com, cto@example.com',
        'slack_webhook_url' => 'https://hooks.slack.test/T1',
        'webhook_url' => 'https://alerts.example.test/spectra',
    ]);
});

/**
 * Store calls made in the last few minutes, the given number of them failed.
 */
function recentCalls(int $succeeded, int $failed, array $attributes = []): void
{
    SpectraRequest::factory()->count($succeeded)->create(['status_code' => 200, 'created_at' => now()->subMinutes(2), ...$attributes]);
    SpectraRequest::factory()->count($failed)->create(['status_code' => 500, 'created_at' => now()->subMinutes(2), ...$attributes]);
}

it('fires once, notifies every channel, and stays quiet within the cooldown', function () {
    recentCalls(succeeded: 6, failed: 4);

    $this->artisan('spectra:check-alerts')->expectsOutputToContain('FIRING')->assertSuccessful();
    $this->travel(10)->minutes();
    $this->artisan('spectra:check-alerts')->assertSuccessful();

    Event::assertDispatchedTimes(AlertTriggered::class, 1);
    Event::assertDispatched(AlertTriggered::class, fn (AlertTriggered $event) => $event->value === 0.4);
    Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example.com') && $mail->hasTo('cto@example.com') && ! $mail->resolved);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/T1' && Str::contains($request['text'], 'High error rate: 40%'));
    Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test/spectra' && $request['status'] === 'firing' && $request['value'] === 0.4);
});

it('notifies again after the cooldown while the rule keeps firing', function () {
    recentCalls(succeeded: 6, failed: 4);
    $this->artisan('spectra:check-alerts');

    $this->travel(61)->minutes();
    recentCalls(succeeded: 1, failed: 5);
    $this->artisan('spectra:check-alerts');

    Event::assertDispatchedTimes(AlertTriggered::class, 2);
});

it('resolves once the rule recovers', function () {
    recentCalls(succeeded: 6, failed: 4);
    $this->artisan('spectra:check-alerts');

    $this->travel(20)->minutes();
    recentCalls(succeeded: 10, failed: 0);
    $this->artisan('spectra:check-alerts')->expectsOutputToContain('ok');

    Event::assertDispatched(AlertResolved::class, fn (AlertResolved $event) => $event->value === 0.0);
    Http::assertSent(fn ($request) => $request->url() === 'https://alerts.example.test/spectra' && $request['status'] === 'resolved');
});

it('waits for enough calls before judging a rate, and watches only its provider', function () {
    recentCalls(succeeded: 1, failed: 2);
    recentCalls(succeeded: 0, failed: 9, attributes: ['provider' => 'anthropic']);
    config()->set('spectra.alerts.rules', [
        ['name' => 'OpenAI errors', 'metric' => 'error_rate', 'above' => 0.2, 'provider' => 'openai', 'min_requests' => 5],
    ]);

    $this->artisan('spectra:check-alerts')->assertSuccessful();

    Event::assertNotDispatched(AlertTriggered::class);
});

it('measures latency percentiles and spend', function () {
    foreach ([100, 200, 300, 400, 5000] as $latency) {
        SpectraRequest::factory()->create(['status_code' => 200, 'latency_ms' => $latency, 'total_cost_in_cents' => 30, 'created_at' => now()->subMinute()]);
    }
    config()->set('spectra.alerts.rules', [
        ['name' => 'Slow', 'metric' => 'latency_p95', 'above' => 1000, 'min_requests' => 5],
        ['name' => 'Spend', 'metric' => 'cost', 'below' => 100],
    ]);

    $this->artisan('spectra:check-alerts')->assertSuccessful();

    // p95 of five calls is the fifth (nearest rank); spend is 150 cents, not below 100.
    Event::assertDispatched(AlertTriggered::class, fn (AlertTriggered $event) => $event->rule->name === 'Slow' && $event->value === 5000.0);
    Event::assertDispatchedTimes(AlertTriggered::class, 1);
});

it('rejects a rule it cannot check', function (array $rule, string $message) {
    expect(fn () => AlertRule::fromConfig($rule))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no name' => [['metric' => 'cost', 'above' => 1], 'needs a name'],
    'unknown metric' => [['name' => 'x', 'metric' => 'tokens', 'above' => 1], 'unknown metric'],
    'no threshold' => [['name' => 'x', 'metric' => 'cost'], 'either `above` or `below`'],
    'both thresholds' => [['name' => 'x', 'metric' => 'cost', 'above' => 1, 'below' => 2], 'either `above` or `below`'],
]);

it('shows each rule\'s value and state on the dashboard, or why the rules can\'t be read', function () {
    recentCalls(succeeded: 6, failed: 4);
    $this->artisan('spectra:check-alerts');

    $alerts = app()->call([app(AlertsController::class), '__invoke'])->toArray();

    expect($alerts['error'])->toBeNull()
        ->and($alerts['alerts'][0]->toArray())->toMatchArray([
            'name' => 'High error rate',
            'rule' => 'Error rate above 20% over 15 minutes',
            'value' => '40%',
            'firing' => true,
        ]);

    config()->set('spectra.alerts.rules', [['name' => 'Broken', 'metric' => 'nope', 'above' => 1]]);

    expect(app()->call([app(AlertsController::class), '__invoke'])->toArray()['error'])->toContain('unknown metric');
});
