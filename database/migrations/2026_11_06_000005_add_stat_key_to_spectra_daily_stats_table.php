<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnection(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Give each daily stat row a unique key, so saving a call is one atomic
     * upsert per row and the global rows (whose trackable columns are NULL,
     * which a unique index treats as all different) can't be created twice.
     *
     * No backfill is needed: rows without a key keep counting, because every
     * reader sums them, and a rebuild replaces them.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('spectra_daily_stats', 'stat_key')) {
            $schema->table('spectra_daily_stats', function (Blueprint $table) {
                $table->string('stat_key', 32)->nullable();
                $table->unsignedInteger('latency_samples')->nullable();
            });
        }

        if (! $schema->hasIndex('spectra_daily_stats', 'spectra_daily_stats_stat_key_unique')) {
            $schema->table('spectra_daily_stats', function (Blueprint $table) {
                $table->unique('stat_key', 'spectra_daily_stats_stat_key_unique');
            });
        }

        // Screens that list trackables over a period start from this one.
        if (! $schema->hasIndex('spectra_daily_stats', 'spectra_daily_stats_date_trackable')) {
            $schema->table('spectra_daily_stats', function (Blueprint $table) {
                $table->index(['date', 'trackable_type', 'trackable_id'], 'spectra_daily_stats_date_trackable');
            });
        }

        // The key replaces the old unique index, which never held for the global
        // rows; nothing filters on the model type index without a date first.
        if ($schema->hasIndex('spectra_daily_stats', 'spectra_daily_stats_unique')) {
            $schema->table('spectra_daily_stats', function (Blueprint $table) {
                $table->dropUnique('spectra_daily_stats_unique');
            });
        }

        if ($schema->hasIndex('spectra_daily_stats', 'spectra_daily_stats_model_type_date_index')) {
            $schema->table('spectra_daily_stats', function (Blueprint $table) {
                $table->dropIndex('spectra_daily_stats_model_type_date_index');
            });
        }

        // Requests keep model names of up to 255 characters; a longer one must
        // not lose its stats. This widens in place, once the index on it is gone.
        $schema->table('spectra_daily_stats', function (Blueprint $table) {
            $table->string('model', 255)->change();
        });
    }

    /**
     * Drop the key and restore the old indexes. Rows that duplicate each other
     * can't be put back under the old unique index, so it comes back plain.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_daily_stats', function (Blueprint $table) {
            $table->dropUnique('spectra_daily_stats_stat_key_unique');
            $table->dropIndex('spectra_daily_stats_date_trackable');
            $table->dropColumn(['stat_key', 'latency_samples']);
            $table->index(['date', 'provider', 'model', 'model_type', 'trackable_type', 'trackable_id'], 'spectra_daily_stats_unique');
            $table->index(['model_type', 'date']);
        });
    }
};
