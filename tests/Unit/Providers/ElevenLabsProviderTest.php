<?php

declare(strict_types=1);

use Spectra\Providers\ElevenLabs\ElevenLabs;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechHandler;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechWithTimestampsHandler;

/**
 * The ElevenLabs text-to-speech paths overlap: a `/with-timestamps` suffix
 * must reach its own handler, not the plain text-to-speech one.
 */
it('resolves the timestamps handler only for paths ending in with-timestamps', function (string $endpoint, string $handler) {
    expect((new ElevenLabs)->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/stream', TextToSpeechHandler::class],
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/stream/with-timestamps', TextToSpeechWithTimestampsHandler::class],
]);
