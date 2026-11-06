<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Http\Request;
use Spectra\Data\ConversationsSummary;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RecentGroups;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\RequestCounter;

/**
 * How many conversations the filters keep, how many had a failed call, and
 * what a conversation costs and how many turns it has on average.
 */
class GetConversationsSummary
{
    /**
     * Create a new get conversations summary action instance.
     */
    public function __construct(
        private readonly BuildConversationQuery $buildConversationQuery,
        private readonly RecentGroups $recentGroups,
        private readonly RequestCounter $counter,
    ) {}

    /**
     * Get the conversation count, failed count and average cost and turns for the filters.
     */
    public function __invoke(Request $request, DateRange $dateRange): ConversationsSummary
    {
        if ($this->recentGroups->isLarge($dateRange)) {
            return $this->estimate($request, $dateRange);
        }

        $conversations = ($this->buildConversationQuery)($request, $dateRange)->toQuery()->toBase();

        $totals = (new SpectraRequest)->getConnection()->query()
            ->fromSub($conversations, 'conversations')
            ->selectRaw('COUNT(*) as conversation_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN failed_count > 0 THEN 1 ELSE 0 END), 0) as failed_conversation_count')
            ->selectRaw('COALESCE(SUM(cost), 0) as total_cost')
            ->selectRaw('COALESCE(AVG(turn_count), 0) as avg_turn_count')
            ->first();

        $conversationCount = (int) ($totals->conversation_count ?? 0);
        $totalCost = (float) ($totals->total_cost ?? 0);

        return new ConversationsSummary(
            conversation_count: $conversationCount,
            failed_conversation_count: (int) ($totals->failed_conversation_count ?? 0),
            total_cost_in_cents: $totalCost,
            avg_cost_in_cents: $conversationCount > 0 ? $totalCost / $conversationCount : 0.0,
            avg_turn_count: round((float) ($totals->avg_turn_count ?? 0), 1),
        );
    }

    /**
     * Scale the latest conversations up to the range. Not every call of a range
     * belongs to a conversation, so the sample decides how many of them do.
     */
    private function estimate(Request $request, DateRange $dateRange): ConversationsSummary
    {
        $calls = $this->buildConversationQuery->matchingConversationIds($request, $dateRange);
        $count = $this->counter->count($calls, $request, $dateRange);
        $sample = $this->recentGroups->sample($calls, 'conversation_id', $count->rows);

        $conversations = RequestAggregateQuery::make($dateRange)
            ->forConversations($sample['ids'])
            ->groupByColumns('conversation_id')
            ->conversationTotals()
            ->get();

        $sampled = max($conversations->count(), 1);
        $totalCost = (float) $conversations->sum('cost') / $sampled * $sample['estimate'];

        return new ConversationsSummary(
            conversation_count: $sample['estimate'],
            failed_conversation_count: (int) round($conversations->where('failed_count', '>', 0)->count() / $sampled * $sample['estimate']),
            total_cost_in_cents: $totalCost,
            avg_cost_in_cents: $sample['estimate'] > 0 ? $totalCost / $sample['estimate'] : 0.0,
            avg_turn_count: round((float) $conversations->avg('turn_count'), 1),
            estimated: true,
        );
    }
}
