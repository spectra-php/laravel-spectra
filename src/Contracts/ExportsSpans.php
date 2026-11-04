<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Optional capability of a RequestExporter: it also exports the spans that
 * are not AI calls (Spectra::span() blocks and the request, job or command
 * of a trace). An exporter without it still exports every AI call.
 */
interface ExportsSpans
{
    /**
     * Send a span that is not an AI call to the exporter's backend.
     *
     * @param  array<string, mixed>  $span  A stored span's columns (trace_id, span_id, parent_span_id, name, kind, started_at, ended_at, status, ...)
     */
    public function exportSpan(array $span): void;
}
