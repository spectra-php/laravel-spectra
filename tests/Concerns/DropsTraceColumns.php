<?php

declare(strict_types=1);

namespace Spectra\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puts spectra_requests back to how it is before the trace migrations run,
 * for tests of what the screens show until then.
 */
trait DropsTraceColumns
{
    /**
     * Drop the columns the trace timing and span migrations add.
     */
    protected function dropTraceColumns(): void
    {
        Schema::table('spectra_requests', function (Blueprint $table) {
            $table->dropIndex('spectra_requests_trace_id_started_at_index');
            $table->dropColumn(['started_at', 'origin', 'span_id']);
        });
    }
}
