<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersRequests;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The conversations screen's query: one row of totals per conversation, over
 * the calls in the date range. The list pages it and the summary totals it.
 *
 * Filters keep the conversations that hold a matching call, with all their
 * calls in the totals. Search matches part of a conversation id.
 */
class BuildConversationQuery
{
    use FiltersRequests;

    /**
     * Create a new build conversation query action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * Get the per-conversation totals query for the filters and date range.
     */
    public function __invoke(Request $request, DateRange $dateRange): RequestAggregateQuery
    {
        $query = RequestAggregateQuery::make($dateRange)->withConversation();

        if ($request->filled('search') || $this->hasRequestFilters($request)) {
            $query->inConversations($this->matchingConversationIds($request, $dateRange));
        }

        return $query->groupByColumns('conversation_id')->conversationTotals();
    }

    /**
     * Build the subquery of conversation ids that hold a call matching the filters or search.
     *
     * @return Builder<SpectraRequest>
     */
    private function matchingConversationIds(Request $request, DateRange $dateRange): Builder
    {
        $calls = SpectraRequest::query()->select('conversation_id')->whereNotNull('conversation_id');

        $dateRange->apply($calls);
        $this->applyRequestFilters($calls, $request, $this->pricingLookup);

        if ($request->filled('search')) {
            $calls->where('conversation_id', 'like', "%{$request->input('search')}%");
        }

        return $calls;
    }
}
