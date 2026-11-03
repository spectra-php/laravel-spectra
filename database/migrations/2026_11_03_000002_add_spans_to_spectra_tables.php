<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('spectra.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('spectra_requests', 'span_id')) {
            $schema->table('spectra_requests', function (Blueprint $table) {
                $table->string('span_id', 16)->nullable()->after('parent_span_id');
            });
        }

        if ($schema->hasTable('spectra_spans')) {
            return;
        }

        $schema->create('spectra_spans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trace_id', 64);
            $table->string('span_id', 16)->unique();
            $table->string('parent_span_id', 16)->nullable();
            $table->string('name');
            $table->string('kind', 20);
            $table->string('origin')->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3);
            $table->unsignedInteger('duration_ms');
            $table->string('status', 10);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->json('attributes')->nullable();

            $table->index(['trace_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('spectra_spans');

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->dropColumn('span_id');
        });
    }
};
