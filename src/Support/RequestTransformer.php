<?php

declare(strict_types=1);

namespace Spectra\Support;

use Spectra\Models\SpectraRequest;

/**
 * Transforms a persisted SpectraRequest into a clean array for integrations.
 *
 * This is the single point of data extraction for all external integrations
 * (OpenTelemetry, Datadog, custom webhooks, events, etc.). Override this class
 * to control what data gets exported.
 *
 * Configure your custom transformer in config/spectra.php:
 *   'integrations.request_transformer' => \App\MyTransformer::class,
 */
class RequestTransformer
{
    /**
     * Transform a SpectraRequest into a clean data array.
     *
     * @return array<string, mixed>
     */
    public function transform(SpectraRequest $request): array
    {
        $data = $request->makeHidden($this->excludedFields())->toArray();

        // Spans start at started_at (to the millisecond); rows saved before
        // that column existed fall back to created_at (to the second).
        $data['started_at'] = $request->started_at ?? $request->created_at ?? now();
        unset($data['created_at']);

        // Fallback for legacy records persisted before completed_at column existed
        $data['completed_at'] = $request->completed_at ?? $data['started_at'];

        // completed_at is stored to the second, so it can fall just before a
        // started_at that has milliseconds; a span never ends before it starts.
        if ($data['completed_at']->lessThan($data['started_at'])) {
            $data['completed_at'] = $data['started_at'];
        }

        // Computed field not stored on the model
        $data['is_failed'] = $request->isFailed();

        // Preserve float type (decimal:2 cast serializes as string)
        if (isset($data['tokens_per_second'])) {
            $data['tokens_per_second'] = (float) $data['tokens_per_second'];
        }

        return $data;
    }

    /**
     * Fields to exclude from the transformer output.
     *
     * Override in a custom transformer to control what gets exported.
     *
     * @return array<string>
     */
    protected function excludedFields(): array
    {
        return [
            'request',
            'response',
            'media_storage_path',
            'batch_id',
            'expires_at',
            // Dashboard-specific appended attributes
            'provider_display_name',
            'formatted_created_at',
            'created_at_human',
        ];
    }
}
