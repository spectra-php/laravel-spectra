<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

/**
 * Incremental parser for streamed provider responses.
 *
 * Bytes are pushed as the application reads them and complete events come
 * out as decoded arrays. Three wire formats are understood:
 *
 * - Server-sent events (`data: {...}` blocks; OpenAI, Anthropic, Cohere, ...)
 * - Newline-delimited JSON (Ollama)
 * - A streamed JSON array (`[{...},{...}]`; Gemini without `alt=sse`)
 *
 * The format is taken from the Content-Type when it is specific, otherwise it
 * is sniffed from the first bytes.
 */
class StreamEventParser
{
    public const SSE = 'sse';

    public const NDJSON = 'ndjson';

    public const JSON_ARRAY = 'json_array';

    protected ?string $format;

    protected string $buffer = '';

    /** @var list<string> Pending `data:` lines of the current SSE event. */
    protected array $dataLines = [];

    protected ?string $eventName = null;

    // JSON-array scanner state
    protected int $depth = 0;

    protected bool $inString = false;

    protected bool $escaped = false;

    protected string $object = '';

    public function __construct(?string $contentType = null)
    {
        $this->format = match (true) {
            $contentType !== null && str_contains($contentType, 'text/event-stream') => self::SSE,
            $contentType !== null && (str_contains($contentType, 'ndjson') || str_contains($contentType, 'jsonl')) => self::NDJSON,
            default => null,
        };
    }

    /**
     * Feed raw bytes; returns the events completed by them.
     *
     * @return list<array<string, mixed>>
     */
    public function push(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }

        if ($this->format === null) {
            $this->buffer .= $bytes;
            $this->format = $this->sniff($this->buffer);

            if ($this->format === null) {
                return [];
            }

            $bytes = $this->buffer;
            $this->buffer = '';
        }

        return $this->format === self::JSON_ARRAY
            ? $this->scanJsonArray($bytes)
            : $this->splitLines($bytes);
    }

    /**
     * Flush whatever is left once the stream has ended.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): array
    {
        if ($this->format === null) {
            $this->format = $this->sniff($this->buffer.' ') ?? self::NDJSON;
            $bytes = $this->buffer;
            $this->buffer = '';

            $events = $this->format === self::JSON_ARRAY ? $this->scanJsonArray($bytes) : $this->splitLines($bytes);
        } else {
            $events = [];
        }

        if ($this->format !== self::JSON_ARRAY && $this->buffer !== '') {
            $events = [...$events, ...$this->line($this->buffer)];
            $this->buffer = '';
        }

        if ($this->format === self::SSE) {
            $events = [...$events, ...$this->dispatchSseEvent()];
        }

        return $events;
    }

    public function format(): ?string
    {
        return $this->format;
    }

    protected function sniff(string $buffer): ?string
    {
        $trimmed = ltrim($buffer);

        if (blank($trimmed)) {
            return null;
        }

        return match (true) {
            $trimmed[0] === '[' => self::JSON_ARRAY,
            $trimmed[0] === '{' => self::NDJSON,
            str_starts_with($trimmed, 'data:'), str_starts_with($trimmed, 'event:'),
            str_starts_with($trimmed, 'id:'), str_starts_with($trimmed, ':') => self::SSE,
            strlen($trimmed) < 6 => null,
            default => self::SSE,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function splitLines(string $bytes): array
    {
        $this->buffer .= $bytes;
        $events = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            $events = [...$events, ...$this->line(rtrim($line, "\r"))];
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function line(string $line): array
    {
        if ($this->format === self::NDJSON) {
            $decoded = $this->decode(trim($line));

            return $decoded !== null ? [$decoded] : [];
        }

        // Server-sent events: a blank line ends the event.
        if (blank($line)) {
            return $this->dispatchSseEvent();
        }

        if (str_starts_with($line, ':')) {
            return [];
        }

        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

        if ($field === 'data') {
            $this->dataLines[] = $value;
        } elseif ($field === 'event') {
            $this->eventName = trim($value);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function dispatchSseEvent(): array
    {
        $data = implode("\n", $this->dataLines);
        $eventName = $this->eventName;

        $this->dataLines = [];
        $this->eventName = null;

        if (blank($data) || trim($data) === '[DONE]') {
            return [];
        }

        $decoded = $this->decode($data);

        if ($decoded === null) {
            return [];
        }

        // Some providers name the event only on the `event:` line.
        if (filled($eventName) && ! isset($decoded['type'])) {
            $decoded['type'] = $eventName;
        }

        return [$decoded];
    }

    /**
     * Emit each top-level object of a streamed JSON array as soon as it closes.
     *
     * @return list<array<string, mixed>>
     */
    protected function scanJsonArray(string $bytes): array
    {
        $events = [];
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $char = $bytes[$i];

            if ($this->depth === 0) {
                // Between elements: skip '[', ',', ']' and whitespace.
                if ($char === '{') {
                    $this->depth = 1;
                    $this->object = '{';
                }

                continue;
            }

            $this->object .= $char;

            if ($this->inString) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($char === '\\') {
                    $this->escaped = true;
                } elseif ($char === '"') {
                    $this->inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $this->inString = true;
            } elseif ($char === '{' || $char === '[') {
                $this->depth++;
            } elseif ($char === '}' || $char === ']') {
                $this->depth--;

                if ($this->depth === 0) {
                    $decoded = $this->decode($this->object);
                    $this->object = '';

                    if ($decoded !== null) {
                        $events[] = $decoded;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decode(string $json): ?array
    {
        if (blank($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
