<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Illuminate\Support\Facades\Date;
use Spectra\Data\Responses\MaintenanceStatusResponse;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Maintenance\MaintenanceAccess;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Tracking\RecordingSwitch;

/**
 * Everything the maintenance screen shows: access, recording state, stored
 * totals, what a prune with the chosen retention would delete, and the last
 * prune and stats rebuild.
 */
class GetMaintenanceStatus
{
    /**
     * Create a new get maintenance status action instance.
     */
    public function __construct(
        private readonly PreviewPrune $previewPrune,
    ) {}

    /**
     * Get the maintenance status, previewing a prune with the given retention.
     */
    public function __invoke(int $hours, ?int $statsDays = null): MaintenanceStatusResponse
    {
        $oldest = SpectraRequest::query()->min('created_at');

        return new MaintenanceStatusResponse(
            enabled: MaintenanceAccess::enabled(),
            can_manage: MaintenanceAccess::allowed(),
            recording_paused: RecordingSwitch::isPaused(),
            totals: [
                'requests' => SpectraRequest::query()->count(),
                'spans' => PruneEntries::spansTableExists() ? SpectraSpan::query()->count() : 0,
                'daily_stats' => SpectraDailyStat::query()->count(),
                'oldest_request_at' => $oldest === null ? null : Date::parse($oldest)->toIso8601String(),
            ],
            preview: ($this->previewPrune)($hours, $statsDays),
            last_prune: MaintenanceHistory::last(MaintenanceHistory::PRUNE),
            last_rebuild: MaintenanceHistory::last(MaintenanceHistory::REBUILD_STATS),
            pending: [
                'prune' => MaintenanceHistory::isPending(MaintenanceHistory::PRUNE),
                'rebuild_stats' => MaintenanceHistory::isPending(MaintenanceHistory::REBUILD_STATS),
            ],
        );
    }
}
