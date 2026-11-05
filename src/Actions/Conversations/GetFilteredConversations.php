<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

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
    ) {}

    /**
     * Get the sorted page of conversation totals for the filters and date range.
     *
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        $sortBy = self::SORTABLE[(string) $request->input('sort_by')] ?? 'last_started_at';
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        return ($this->buildConversationQuery)($request, $dateRange)
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('conversation_id')
            ->paginate($perPage);
    }
}
