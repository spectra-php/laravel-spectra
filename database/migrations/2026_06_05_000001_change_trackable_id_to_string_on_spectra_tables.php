<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->string('trackable_id')->nullable()->change();
        });

        $schema->table('spectra_daily_stats', function (Blueprint $table) {
            $table->string('trackable_id')->nullable()->change();
        });
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
