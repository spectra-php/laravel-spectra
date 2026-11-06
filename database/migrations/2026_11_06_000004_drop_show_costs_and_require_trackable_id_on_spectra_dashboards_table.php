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
     * Make the Costs page the one switch for a shared dashboard's costs, and tie
     * every dashboard to one trackable. A dashboard that showed costs gets the
     * page, and one for a whole type has no trackable to keep, so it goes.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('spectra_dashboards', 'show_costs')) {
            return;
        }

        $dashboards = $schema->getConnection()->table('spectra_dashboards');

        $dashboards->clone()->whereNull('trackable_id')->delete();

        foreach ($dashboards->clone()->where('show_costs', true)->get(['id', 'sections']) as $dashboard) {
            $sections = json_decode($dashboard->sections ?? '[]', true) ?: [];

            $dashboards->clone()->where('id', $dashboard->id)->update([
                'sections' => json_encode(array_values(array_unique([...$sections, 'costs']))),
            ]);
        }

        $schema->table('spectra_dashboards', function (Blueprint $table) {
            $table->dropColumn('show_costs');
            $table->string('trackable_id')->nullable(false)->change();
        });
    }

    /**
     * Bring back the separate costs switch and dashboards for a whole type.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('spectra_dashboards', function (Blueprint $table) {
            $table->boolean('show_costs')->default(false);
            $table->string('trackable_id')->nullable()->change();
        });
    }
};
