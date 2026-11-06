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
     * Add the fingerprint that groups similar failures for the Errors screen.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('spectra_requests', 'error_fingerprint')) {
            return;
        }

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->string('error_fingerprint', 16)->nullable();

            $table->index(['error_fingerprint', 'created_at'], 'spectra_requests_error_fingerprint_index');
        });
    }

    /**
     * Drop the fingerprint column.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_requests', function (Blueprint $table) {
            $table->dropIndex('spectra_requests_error_fingerprint_index');
            $table->dropColumn('error_fingerprint');
        });
    }
};
