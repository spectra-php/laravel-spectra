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
     * Add the prompt template name and version each call used, to compare versions.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('spectra_requests', 'prompt_name')) {
            return;
        }

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->string('prompt_name', 100)->nullable();
            $table->string('prompt_version', 50)->nullable();

            $table->index(['prompt_name', 'prompt_version', 'created_at'], 'spectra_requests_prompt_index');
        });
    }

    /**
     * Drop the prompt columns.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_requests', function (Blueprint $table) {
            $table->dropIndex('spectra_requests_prompt_index');
            $table->dropColumn(['prompt_name', 'prompt_version']);
        });
    }
};
