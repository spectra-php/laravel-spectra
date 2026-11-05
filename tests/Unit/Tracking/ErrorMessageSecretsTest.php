<?php

declare(strict_types=1);

/**
 * The secrets a transport error quotes never reach the database, with
 * redaction off: Guzzle names the request URL in its message, and Google's
 * key travels in it as `?key=…`.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Redaction\Redactor;

const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
const GEMINI_KEY = 'AIzaSyA0123456789abcdefghijklmnopqrstuv';

function curlMessage(): string
{
    return 'cURL error 6: Could not resolve host: generativelanguage.googleapis.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.GEMINI_URL.'?key='.GEMINI_KEY;
}

beforeEach(function () {
    config(['spectra.storage.redaction.enabled' => false]);
    Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException(curlMessage())]);
});

it('drops the query string and the key from a connection failure message', function () {
    expect(fn () => Http::post(GEMINI_URL.'?key='.GEMINI_KEY, ['contents' => []]))
        ->toThrow(ConnectionException::class);

    $message = SpectraRequest::query()->sole()->error_message;

    expect($message)->not->toContain(GEMINI_KEY)
        ->not->toContain('?key=')
        ->toEndWith('for '.GEMINI_URL);
});

it('drops them from the span that wrapped the failed call too', function () {
    expect(fn () => Spectra::span('ask-gemini', fn () => Http::post(GEMINI_URL.'?key='.GEMINI_KEY, ['contents' => []])))
        ->toThrow(ConnectionException::class);

    $message = SpectraSpan::query()->where('name', 'ask-gemini')->sole()->error_message;

    expect($message)->not->toContain(GEMINI_KEY)
        ->not->toContain('?key=')
        ->toEndWith('for '.GEMINI_URL);
});

it('masks keys and drops query strings in any error text', function () {
    $redactor = app(Redactor::class);

    expect($redactor->scrubSecrets('failed for https://a.test/x?token=1&b=2 and https://b.test/y?k=v then sk-proj-abcdefghijklmnopqrstuvwxyz done'))
        ->toBe('failed for https://a.test/x and https://b.test/y then [REDACTED:api_key] done')
        ->and($redactor->scrubSecrets('plain text'))->toBe('plain text')
        ->and($redactor->scrubSecrets(null))->toBeNull();
});
