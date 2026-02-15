<?php

namespace Spectra\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after an AI request has been persisted and is ready for integrations.
 *
 * Listen to this event to perform custom actions when AI requests are tracked,
 * such as sending data to custom observability backends, triggering alerts,
 * or building custom analytics pipelines.
 *
 * The $request array is produced by RequestTransformer and contains a clean
 * snapshot of the request data (provider, model, tokens, costs, etc.).
 *
 * @see \Spectra\Support\RequestTransformer
 */
class RequestTracked
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $request  Transformed request data from RequestTransformer
     */
    public function __construct(
        public readonly array $request,
    ) {}
}
