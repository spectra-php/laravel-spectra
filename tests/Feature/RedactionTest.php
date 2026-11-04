<?php

declare(strict_types=1);

/**
 * Redaction: with spectra.storage.redaction on, personal data and secrets are
 * masked in everything Spectra stores that can hold free text (request and
 * response bodies, error messages, span attributes, score comments), before
 * it is saved or queued. Off by default.
 */

use Illuminate\Support\Facades\Queue;
use Spectra\Facades\Spectra;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Redaction\Redactor;

beforeEach(function () {
    config()->set('spectra.storage.redaction.enabled', true);
});

/**
 * Track one chat call whose prompt and reply carry the given text.
 */
function trackChatWith(string $prompt, string $reply = 'Sure.'): SpectraRequest
{
    Spectra::track('openai', 'gpt-4o', fn () => [
        'choices' => [['message' => ['role' => 'assistant', 'content' => $reply]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
    ], ['request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => $prompt]]]]);

    return SpectraRequest::query()->latest('created_at')->firstOrFail();
}

it('masks each built-in kind of personal data and secret', function (string $text, string $expected) {
    expect(app(Redactor::class)->redact($text))->toBe($expected);
})->with([
    'email' => ['Write to ada.lovelace+ai@example.co.uk today', 'Write to [REDACTED:email] today'],
    'card number' => ['Card 4242 4242 4242 4242 expires soon', 'Card [REDACTED:credit_card] expires soon'],
    'not a card number' => ['Order 1234567812345678 shipped', 'Order 1234567812345678 shipped'],
    'openai key' => ['key sk-proj-abcdefghijklmnopqrstuvwxyz123456', 'key [REDACTED:api_key]'],
    'bearer token' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature', 'Authorization: [REDACTED:api_key]'],
    'ip address' => ['from 192.168.10.24 at noon', 'from [REDACTED:ip_address] at noon'],
    'phone' => ['call +1 (415) 555-0123 now', 'call [REDACTED:phone] now'],
]);

it('masks the stored request and response but keeps the payload\'s shape and the usage', function () {
    $request = trackChatWith('My email is ada@example.com', 'I emailed ada@example.com');

    expect($request->request['messages'][0])->toBe(['role' => 'user', 'content' => 'My email is [REDACTED:email]'])
        ->and($request->response['choices'][0]['message']['content'])->toBe('I emailed [REDACTED:email]')
        ->and($request->prompt_tokens)->toBe(10);
});

it('uses only the chosen patterns, custom patterns and the callback', function () {
    config()->set('spectra.storage.redaction.patterns', ['email']);
    config()->set('spectra.storage.redaction.custom', ['account' => '/ACC-\d{8}/']);
    Spectra::redactUsing(fn (string $text) => str_replace('Project Falcon', '[REDACTED:codename]', $text));

    expect(app(Redactor::class)->redact(['note' => 'ada@example.com ACC-12345678 Project Falcon from 10.0.0.1']))
        ->toBe(['note' => '[REDACTED:email] [REDACTED:account] [REDACTED:codename] from 10.0.0.1']);
});

it('masks span attributes and error messages, and score comments', function () {
    Spectra::span('lookup', fn () => null, ['customer_email' => 'ada@example.com']);
    Spectra::score('helpful', false, traceId: 't', comment: 'Reach me at +44 20 7946 0958');

    expect(SpectraSpan::query()->sole()->attributes)->toBe(['customer_email' => '[REDACTED:email]'])
        ->and(SpectraScore::query()->sole()->comment)->toBe('Reach me at [REDACTED:phone]');
});

it('masks a request before it goes on the queue', function () {
    Queue::fake();
    config()->set('spectra.queue.enabled', true);

    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Ok']]]], [
        'request_data' => ['messages' => [['role' => 'user', 'content' => 'Card 4242424242424242']]],
    ]);

    Queue::assertPushed(PersistSpectraRequestJob::class, fn (PersistSpectraRequestJob $job) => ! str_contains(serialize($job), '4242424242424242'));
});

it('stores everything as sent when redaction is off', function () {
    config()->set('spectra.storage.redaction.enabled', false);

    expect(trackChatWith('My email is ada@example.com')->request['messages'][0]['content'])->toBe('My email is ada@example.com');
});

it('rejects an unknown built-in pattern name', function () {
    config()->set('spectra.storage.redaction.patterns', ['email', 'passport']);

    expect(fn () => app(Redactor::class)->redact('x'))->toThrow(InvalidArgumentException::class, 'Unknown Spectra redaction pattern [passport]');
});
