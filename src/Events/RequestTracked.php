<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spectra\Support\RequestTransformer;

/**
 * Dispatched once a tracked AI request is ready for integrations: after it
 * is saved, or right after tracking when `spectra.storage.store_requests` is
 * off and nothing is saved.
 *
 * Listen to this event to perform custom actions when AI requests are tracked,
 * such as sending data to custom observability backends, triggering alerts,
 * or building custom analytics pipelines.
 *
 * The $request array is produced by RequestTransformer and contains a clean
 * snapshot of the request data (provider, model, tokens, costs, etc.).
 *
 * @see RequestTransformer
 */
class RequestTracked
{
    use Dispatchable;

    /**
     * Create a new request tracked event instance.
     *
     * @param  array<string, mixed>  $request  Transformed request data from RequestTransformer
     */
    public function __construct(
        public readonly array $request,
    ) {}
}
