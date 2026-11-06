<?php

declare(strict_types=1);

use Spectra\Support\Tracking\StreamEventParser;

/**
 * Bytes arrive in arbitrary chunks; events must come out whole and in order.
 */
function parseInChunks(StreamEventParser $parser, string $body, int $size): array
{
    $events = [];

    foreach (str_split($body, $size) as $chunk) {
        array_push($events, ...$parser->push($chunk));
    }

    return [...$events, ...$parser->finish()];
}

it('parses server-sent events split across reads', function () {
    $body = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n: keep-alive\n\ndata: {\"a\":1}\n\ndata: [DONE]\n\n";

    $events = parseInChunks(new StreamEventParser('text/event-stream'), $body, 5);

    expect($events)->toBe([['type' => 'message_start'], ['a' => 1]]);
});

it('takes the event type from the event line when the data has none', function () {
    $events = parseInChunks(new StreamEventParser('text/event-stream'), "event: message-end\ndata: {\"delta\":{}}\n\n", 7);

    expect($events)->toBe([['delta' => [], 'type' => 'message-end']]);
});

it('joins multi-line data fields', function () {
    $events = parseInChunks(new StreamEventParser('text/event-stream'), "data: {\"a\":\ndata: 2}\n\n", 3);

    expect($events)->toBe([['a' => 2]]);
});

it('parses newline-delimited JSON', function () {
    $events = parseInChunks(new StreamEventParser('application/x-ndjson'), "{\"a\":1}\n{\"b\":2}", 4);

    expect($events)->toBe([['a' => 1], ['b' => 2]]);
});

it('parses a streamed JSON array, braces inside strings included', function () {
    $body = '[{"text":"a } b","n":[1,2]},'."\n".'{"text":"say \"hi\" {"}]';

    $events = parseInChunks(new StreamEventParser('application/json'), $body, 3);

    expect($events)->toBe([['text' => 'a } b', 'n' => [1, 2]], ['text' => 'say "hi" {']]);
});

it('sniffs the format when the content type is unspecific', function () {
    $parser = new StreamEventParser;

    $events = parseInChunks($parser, "data: {\"x\":1}\n\n", 2);

    expect($events)->toBe([['x' => 1]])
        ->and($parser->format())->toBe(StreamEventParser::SSE);
});
