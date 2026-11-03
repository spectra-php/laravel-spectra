<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
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

        if (! $schema->hasColumn('spectra_requests', 'cached_tokens')) {
            $this->addRequestColumns($schema);
        }

        if (! $schema->hasColumn('spectra_daily_stats', 'total_cached_tokens')) {
            $this->addDailyStatsColumns($schema);
        }
    }

    protected function addRequestColumns(Builder $schema): void
    {
        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->default(0)->after('completion_tokens');
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('cached_tokens');
            $table->unsignedInteger('page_count')->nullable()->after('video_count');
            $table->string('currency', 3)->nullable()->after('total_cost_in_cents');

            $table->string('error_type')->nullable()->after('status_code');
            $table->text('error_message')->nullable()->after('error_type');

            $table->string('parent_span_id', 16)->nullable()->after('trace_id');
            $table->string('conversation_id')->nullable()->after('parent_span_id');
            $table->unsignedInteger('conversation_turn')->nullable()->after('conversation_id');

            $table->string('request_source', 20)->nullable()->after('metadata');
            $table->string('laravel_request_id')->nullable()->after('request_source');
            $table->string('ip_address', 45)->nullable()->after('laravel_request_id');
            $table->text('user_agent')->nullable()->after('ip_address');

            $table->index('conversation_id');
        });
    }

    protected function addDailyStatsColumns(Builder $schema): void
    {
        $schema->table('spectra_daily_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('total_cached_tokens')->default(0)->after('total_reasoning_tokens');
            $table->unsignedBigInteger('total_cache_write_tokens')->default(0)->after('total_cached_tokens');
            $table->unsignedBigInteger('total_pages')->default(0)->after('total_input_characters');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('spectra_daily_stats', function (Blueprint $table) {
            $table->dropColumn(['total_cached_tokens', 'total_cache_write_tokens', 'total_pages']);
        });

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropColumn([
                'cached_tokens',
                'cache_write_tokens',
                'page_count',
                'currency',
                'error_type',
                'error_message',
                'parent_span_id',
                'conversation_id',
                'conversation_turn',
                'request_source',
                'laravel_request_id',
                'ip_address',
                'user_agent',
            ]);
        });
    }
};
