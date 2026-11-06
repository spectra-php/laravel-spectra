<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Indexes with no query of their own: each saved call paid to keep them. */
    protected const REDUNDANT = [
        'spectra_requests' => [
            // Nothing filters on batch_id.
            'spectra_requests_batch_id_index',
            // Prefixes of (trace_id, started_at) and of (trackable_type, trackable_id, created_at).
            'spectra_requests_trace_id_index',
            'spectra_requests_trackable_type_trackable_id_index',
            // Successful means a status range, and failed an OR with error_type:
            // neither can use it, and the screens read their counts from the daily stats.
            'spectra_requests_status_code_created_at_index',
        ],
        // A prefix of (trackable_type, trackable_id, date).
        'spectra_daily_stats' => ['spectra_daily_stats_trackable_type_trackable_id_index'],
    ];

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnection(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Drop the indexes no query needs. This changes no data and takes moments,
     * though each drop briefly waits for running queries on the table.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (self::REDUNDANT as $tableName => $indexes) {
            foreach ($indexes as $index) {
                if (! $schema->hasIndex($tableName, $index)) {
                    continue;
                }

                $schema->table($tableName, function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }

    /**
     * Put the indexes back.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_requests', function (Blueprint $table) {
            $table->index('batch_id');
            $table->index('trace_id');
            $table->index(['trackable_type', 'trackable_id']);
            $table->index(['status_code', 'created_at']);
        });

        Schema::connection($this->getConnection())->table('spectra_daily_stats', function (Blueprint $table) {
            $table->index(['trackable_type', 'trackable_id']);
        });
    }
};
