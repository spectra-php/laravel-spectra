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
     * Create the scores table: feedback and evaluations attached to a call,
     * a trace or a conversation.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('spectra_scores')) {
            return;
        }

        $schema->create('spectra_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('type', 12);
            $table->double('value')->nullable();
            $table->string('label')->nullable();
            $table->text('comment')->nullable();
            $table->string('source', 20);
            $table->uuid('request_id')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('conversation_id')->nullable();
            $table->string('author_type')->nullable();
            $table->string('author_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_id');
            $table->index('trace_id');
            $table->index('conversation_id');
            $table->index(['name', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Drop the scores table.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('spectra_scores');
    }
};
