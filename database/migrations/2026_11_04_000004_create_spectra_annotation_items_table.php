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
     * Create the annotation items table: the traces waiting in each review
     * queue, and who reviewed them.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('spectra_annotation_items')) {
            return;
        }

        $schema->create('spectra_annotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('queue', 100);
            $table->string('trace_id', 64);
            $table->uuid('request_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->string('reviewer_type')->nullable();
            $table->string('reviewer_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['queue', 'trace_id']);
            $table->index(['queue', 'status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Drop the annotation items table.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('spectra_annotation_items');
    }
};
