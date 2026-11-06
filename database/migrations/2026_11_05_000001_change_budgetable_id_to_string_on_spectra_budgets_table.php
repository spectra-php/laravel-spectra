<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('spectra.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $this->needsChange($schema)) {
            return;
        }

        $schema->table('spectra_budgets', function (Blueprint $table) {
            $table->string('budgetable_id')->change();
        });
    }

    protected function needsChange(Builder $schema): bool
    {
        if (! $schema->hasColumn('spectra_budgets', 'budgetable_id')) {
            return false;
        }

        return Str::lower($schema->getColumnType('spectra_budgets', 'budgetable_id')) !== 'varchar';
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('spectra_budgets', function (Blueprint $table) {
            $table->uuid('budgetable_id')->change();
        });
    }
};
