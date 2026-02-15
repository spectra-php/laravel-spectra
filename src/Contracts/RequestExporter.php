<?php

namespace Spectra\Contracts;

/**
 * Generic contract for exporting tracked AI requests to external integrations.
 *
 * Implementations receive a clean data array (produced by RequestTransformer)
 * and are responsible for transforming and sending it to their specific backend
 * (OpenTelemetry, Datadog, webhooks, etc.).
 *
 * @see \Spectra\Support\RequestTransformer
 */
interface RequestExporter
{
    /**
     * Export a single tracked request.
     *
     * @param  array<string, mixed>  $data  Transformed request data from RequestTransformer
     */
    public function export(array $data): void;

    /**
     * Export a batch of tracked requests.
     *
     * @param  array<array<string, mixed>>  $requests  Array of transformed request data
     */
    public function exportBatch(array $requests): void;
}
