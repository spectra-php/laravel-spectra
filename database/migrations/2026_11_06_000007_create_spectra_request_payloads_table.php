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
     * Create the table that holds a call's request and response bodies, so a
     * scan of the calls doesn't read them and they can be kept for less time.
     *
     * Rows saved before this keep their bodies on spectra_requests and are
     * still read from there; nothing has to be moved.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('spectra_request_payloads')) {
            return;
        }

        $schema->create('spectra_request_payloads', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->longText('request')->nullable();
            $table->longText('response')->nullable();
            $table->dateTime('created_at')->nullable()->index();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('spectra_request_payloads');
    }
};
