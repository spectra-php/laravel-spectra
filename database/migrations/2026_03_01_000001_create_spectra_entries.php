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

        if ($schema->hasTable('spectra_requests')) {
            return;
        }

        $schema->create('spectra_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->string('response_id')->nullable()->unique();

            $table->string('provider');
            $table->string('model');
            $table->string('snapshot')->nullable();
            $table->string('model_type', 20)->nullable();
            $table->string('endpoint')->nullable();

            $table->nullableUuidMorphs('trackable');

            $table->longText('request')->nullable();

            $table->longText('response')->nullable();

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);

            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->unsignedInteger('input_characters')->nullable();
            $table->unsignedInteger('image_count')->nullable();
            $table->unsignedInteger('video_count')->nullable();

            $table->decimal('prompt_cost', 12, 6)->default(0);
            $table->decimal('completion_cost', 12, 6)->default(0);
            $table->decimal('total_cost_in_cents', 12, 6)->default(0);
            $table->string('pricing_tier', 20)->nullable();

            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('time_to_first_token_ms')->nullable();
            $table->decimal('tokens_per_second', 8, 2)->nullable();

            $table->boolean('is_reasoning')->default(false);
            $table->string('reasoning_effort', 20)->nullable();

            $table->boolean('is_streaming')->default(false);
            $table->string('finish_reason', 50)->nullable();
            $table->boolean('has_tool_calls')->default(false);
            $table->json('tool_call_counts')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('media_storage_path')->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->index('batch_id');
            $table->index('trace_id');
            $table->index('created_at');
            $table->index(['provider', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['status_code', 'created_at']);
            $table->index(['model_type', 'created_at']);
            $table->index(['trackable_type', 'trackable_id', 'created_at'], 'spectra_requests_trackable_created_index');
        });

        $schema->create('spectra_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('budgetable');
            $table->string('name')->nullable();

            $table->unsignedBigInteger('daily_limit')->nullable();
            $table->unsignedBigInteger('weekly_limit')->nullable();
            $table->unsignedBigInteger('monthly_limit')->nullable();
            $table->unsignedBigInteger('total_limit')->nullable();

            $table->unsignedBigInteger('daily_token_limit')->nullable();
            $table->unsignedBigInteger('weekly_token_limit')->nullable();
            $table->unsignedBigInteger('monthly_token_limit')->nullable();
            $table->unsignedBigInteger('total_token_limit')->nullable();

            $table->unsignedInteger('daily_request_limit')->nullable();
            $table->unsignedInteger('weekly_request_limit')->nullable();
            $table->unsignedInteger('monthly_request_limit')->nullable();

            $table->unsignedTinyInteger('warning_threshold')->default(80);
            $table->unsignedTinyInteger('critical_threshold')->default(95);

            $table->boolean('hard_limit')->default(true);

            $table->json('allowed_providers')->nullable();
            $table->json('allowed_models')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['budgetable_type', 'budgetable_id', 'is_active']);
        });

        $schema->create('spectra_daily_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date');

            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('model_type', 20)->nullable();
            $table->nullableUuidMorphs('trackable');

            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('successful_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->unsignedBigInteger('total_reasoning_tokens')->default(0);

            $table->unsignedBigInteger('total_images')->default(0);
            $table->unsignedBigInteger('total_videos')->default(0);
            $table->decimal('total_duration_seconds', 12, 3)->default(0);
            $table->unsignedBigInteger('total_input_characters')->default(0);

            $table->decimal('total_cost_in_cents', 14, 6)->default(0);

            $table->unsignedBigInteger('total_latency_ms')->default(0);
            $table->unsignedInteger('min_latency_ms')->nullable();
            $table->unsignedInteger('max_latency_ms')->nullable();

            $table->timestamps();

            $table->unique(['date', 'provider', 'model', 'model_type', 'trackable_type', 'trackable_id'], 'spectra_daily_stats_unique');

            $table->index(['model_type', 'date']);
            $table->index(['trackable_type', 'trackable_id', 'date'], 'spectra_daily_stats_trackable_date');
        });

        $schema->create('spectra_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
        });

        $schema->create('spectra_requests_tags', function (Blueprint $table) {
            $table->uuid('request_id');
            $table->uuid('tag_id');

            $table->primary(['request_id', 'tag_id']);
            $table->index('tag_id');

            $table->foreign('request_id')
                ->references('id')
                ->on('spectra_requests')
                ->onDelete('cascade');

            $table->foreign('tag_id')
                ->references('id')
                ->on('spectra_tags')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('spectra_requests_tags');
        $schema->dropIfExists('spectra_tags');
        $schema->dropIfExists('spectra_daily_stats');
        $schema->dropIfExists('spectra_budgets');
        $schema->dropIfExists('spectra_requests');
    }
};
