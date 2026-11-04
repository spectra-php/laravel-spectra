<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * What a span stands for: a block of code wrapped in Spectra::span(), a tool
 * the model asked for, or the unit of work a trace ran in. The kind maps to the OpenTelemetry span kind
 * it is exported as.
 */
enum SpanKind: string
{
    case Span = 'span';
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';
    case Tool = 'tool';

    /** OTLP span kind: 1 internal, 2 server, 5 consumer. */
    public function otlpKind(): int
    {
        return match ($this) {
            self::Span, self::Command, self::Tool => 1,
            self::Request => 2,
            self::Job => 5,
        };
    }
}
