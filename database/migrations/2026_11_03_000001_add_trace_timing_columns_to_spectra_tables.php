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

        if ($schema->hasColumn('spectra_requests', 'started_at')) {
            return;
        }

        $schema->table('spectra_requests', function (Blueprint $table) {
            $table->dateTime('started_at', 3)->nullable()->after('created_at');
            $table->string('origin')->nullable()->after('request_source');

            $table->index(['trace_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_requests', function (Blueprint $table) {
            $table->dropIndex(['trace_id', 'started_at']);
            $table->dropColumn(['started_at', 'origin']);
        });
    }
};
