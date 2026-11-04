<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Http\Request;
use Spectra\Data\ConversationsSummary;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

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
    ) {}

    /**
     * Get the conversation count, failed count and average cost and turns for the filters.
     */
    public function __invoke(Request $request, DateRange $dateRange): ConversationsSummary
    {
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
}
