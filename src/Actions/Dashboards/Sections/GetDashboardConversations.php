<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Illuminate\Support\Facades\Date;
use Spectra\Data\Responses\DashboardPageResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The trackable's conversations for a shared dashboard, newest first: only those
 * whose every call is theirs.
 */
class GetDashboardConversations
{
    /** ISO 8601 with milliseconds, as the screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Get one page of the trackable's conversations in the date range.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange, bool $showCosts): DashboardPageResponse
    {
        $paginated = $scope->withoutShared($scope->calls($dateRange)->whereNotNull('conversation_id'), 'conversation_id')->toBase()
            ->select('conversation_id')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COUNT(DISTINCT conversation_turn) + COALESCE(SUM(CASE WHEN conversation_turn IS NULL THEN 1 ELSE 0 END), 0) as turns')
            ->selectRaw(DashboardScope::FAILED_SQL.' as failed')
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->selectRaw(DashboardScope::TOKENS_SQL.' as tokens')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->selectRaw('MAX(COALESCE(started_at, created_at)) as last_started_at')
            ->groupBy('conversation_id')
            ->latest('last_started_at')
            ->orderBy('conversation_id')
            ->paginate(25);

        /** @var array<int, stdClass> $conversations */
        $conversations = $paginated->items();

        $models = $scope->calls()
            ->whereIn('conversation_id', array_map(fn (stdClass $conversation) => (string) $conversation->conversation_id, $conversations))
            ->select(['conversation_id', 'provider', 'model'])
            ->distinct()
            ->get()
            ->groupBy(fn (SpectraRequest $call) => (string) $call->conversation_id);

        return new DashboardPageResponse(
            data: array_map(fn (stdClass $conversation) => [
                'conversation_id' => (string) $conversation->conversation_id,
                'request_count' => (int) $conversation->requests,
                'turn_count' => (int) $conversation->turns,
                'failed_count' => (int) $conversation->failed,
                'total_cost_in_cents' => $showCosts ? (float) $conversation->cost : null,
                'total_tokens' => (int) $conversation->tokens,
                'started_at' => Date::parse((string) $conversation->first_started_at)->format(self::ISO_MILLISECONDS),
                'last_activity_at' => Date::parse((string) $conversation->last_started_at)->format(self::ISO_MILLISECONDS),
                'models' => $models->get((string) $conversation->conversation_id, collect())
                    ->map(fn (SpectraRequest $call) => ['provider' => $call->provider, 'model' => $call->model])
                    ->values()
                    ->all(),
            ], $conversations),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
        );
    }
}
