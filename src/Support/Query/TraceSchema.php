<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Support\Facades\Schema;
use Spectra\Models\SpectraRequest;

/**
 * Whether the trace columns (`started_at`, `origin`, `span_id`) exist on
 * spectra_requests yet. The writer keeps recording calls without them until
 * the trace migrations run, so the screens that order and time calls by
 * them say the migration is missing instead of failing on a bare column.
 */
class TraceSchema
{
    /**
     * Determine if the trace timing and span migrations have run.
     */
    public function hasTraceColumns(): bool
    {
        return rescue(
            fn () => Schema::connection((new SpectraRequest)->getConnectionName())->hasColumns('spectra_requests', ['started_at', 'origin', 'span_id']),
            false,
            report: false,
        );
    }
}
