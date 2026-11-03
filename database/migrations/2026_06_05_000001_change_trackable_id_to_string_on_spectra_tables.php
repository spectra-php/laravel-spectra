<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Run the migrations.
     *
     * Widens trackable_id from UUID to string so that consuming apps can
     * associate requests with models using UUIDs, ULIDs, or integer IDs.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['spectra_requests', 'spectra_daily_stats'] as $tableName) {
            if (! $this->needsChange($schema, $tableName)) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table) {
                $table->string('trackable_id')->nullable()->change();
            });
        }
    }

    /**
     * On a dedicated connection the Spectra database survives the app's
     * migrate:fresh, so the column may already be a string, or the table may
     * not exist yet, while this migration shows as pending.
     */
    protected function needsChange(Builder $schema, string $tableName): bool
    {
        if (! $schema->hasColumn($tableName, 'trackable_id')) {
            return false;
        }

        return Str::lower($schema->getColumnType($tableName, 'trackable_id')) !== 'varchar';
    }

    /**
     * Reverse the migrations.
     *
     * Rollback only succeeds when every existing trackable_id is a valid UUID.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->uuid('trackable_id')->nullable()->change();
        });

        $schema->table('spectra_daily_stats', function (Blueprint $table) {
            $table->uuid('trackable_id')->nullable()->change();
        });
    }
};
