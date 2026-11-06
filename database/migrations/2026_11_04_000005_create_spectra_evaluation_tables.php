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
     * Create the dataset and evaluation tables: examples to run AI code on,
     * and each run of an evaluation with its result per example.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('spectra_datasets')) {
            return;
        }

        $schema->create('spectra_datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $schema->create('spectra_dataset_examples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dataset_id');
            $table->json('input');
            $table->json('expected')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('source_request_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['dataset_id', 'created_at']);
        });

        $schema->create('spectra_eval_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('evaluation', 100);
            $table->string('dataset', 100);
            $table->string('status', 20);
            $table->unsignedInteger('example_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();

            $table->index(['evaluation', 'started_at']);
        });

        $schema->create('spectra_eval_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('example_id')->nullable();
            $table->string('trace_id', 64);
            $table->json('input');
            $table->json('expected')->nullable();
            $table->json('output')->nullable();
            $table->json('scores')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['run_id', 'created_at']);
            $table->index('example_id');
        });
    }

    /**
     * Drop the dataset and evaluation tables.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['spectra_eval_results', 'spectra_eval_runs', 'spectra_dataset_examples', 'spectra_datasets'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
