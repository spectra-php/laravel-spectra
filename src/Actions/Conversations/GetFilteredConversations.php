<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spectra\Data\RowPage;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RecentGroups;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\RequestCounter;

/**
 * The page of conversations the conversations screen shows, sorted by last
 * activity, start, cost, turns, calls or failures.
 */
class GetFilteredConversations
{
    /** Sort keys the screen sends, mapped to the totals' column aliases. */
    private const SORTABLE = [
        'last_activity' => 'last_started_at',
        'started_at' => 'first_started_at',
        'cost' => 'cost',
        'turns' => 'turn_count',
        'requests' => 'request_count',
        'failed' => 'failed_count',
    ];

    /**
     * Create a new get filtered conversations action instance.
     */
    public function __construct(
        private readonly BuildConversationQuery $buildConversationQuery,
        private readonly RecentGroups $recentGroups,
        private readonly RequestCounter $counter,
    ) {}

    /**
     * Get the sorted page of the list for the filters and date range.
     */
    public function __invoke(Request $request, DateRange $dateRange): RowPage
    {
        $sortBy = self::SORTABLE[(string) $request->input('sort_by')] ?? 'last_started_at';
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        if ($this->recentGroups->isLarge($dateRange)) {
            return $this->getLatestPage($request, $dateRange, $sortBy, $sortDirection, $perPage, $page);
        }

        return new RowPage(paginator: ($this->buildConversationQuery)($request, $dateRange)
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('conversation_id')
            ->paginate($perPage));
    }

    /**
     * Get the page of a range too large to add up: the groups of the latest
     * calls, with the page itself in the requested order, and an estimated total.
     *
     * @param  'asc'|'desc'  $sortDirection
     */
    private function getLatestPage(Request $request, DateRange $dateRange, string $sortBy, string $sortDirection, int $perPage, int $page): RowPage
    {
        $calls = ($this->buildConversationQuery)->matchingConversationIds($request, $dateRange);
        $ids = $this->recentGroups->page($calls, 'conversation_id', $page, $perPage);
        $count = $this->counter->count($calls, $request, $dateRange);
        $estimate = $this->recentGroups->sample($calls, 'conversation_id', $count->rows)['estimate'];

        $rows = $ids === []
            ? new Collection
            : RequestAggregateQuery::make($dateRange)
                ->forConversations($ids)
                ->groupByColumns('conversation_id')
                ->conversationTotals()
                ->orderBy($sortBy, $sortDirection)
                ->get();

        $offset = ($page - 1) * $perPage;

        return new RowPage(
            paginator: new LengthAwarePaginator($rows, max($estimate, $offset + $rows->count() + ($rows->count() === $perPage ? 1 : 0)), $perPage, $page),
            totalIsEstimate: true,
        );
    }
}
