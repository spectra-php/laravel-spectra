<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Conversations\FormatConversationsList;
use Spectra\Actions\Conversations\GetConversationsSummary;
use Spectra\Actions\Conversations\GetFilteredConversations;
use Spectra\Actions\Requests\GetAvailableFilters;
use Spectra\Data\Responses\ConversationsResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/conversations: the conversations screen, one filtered and sorted
 * page of conversations with their totals and the filter options.
 */
class ConversationsController extends BaseApiController
{
    public function __invoke(
        Request $request,
        GetFilteredConversations $getFilteredConversations,
        GetConversationsSummary $getConversationsSummary,
        GetAvailableFilters $getAvailableFilters,
        FormatConversationsList $formatConversationsList,
    ): ConversationsResponse {
        $dateRange = DateRange::fromRequest($request);
        $paginated = $getFilteredConversations($request, $dateRange);
        $filters = $getAvailableFilters();

        return new ConversationsResponse(
            data: $formatConversationsList($paginated),
            current_page: $paginated->currentPage(),
            last_page: $paginated->lastPage(),
            total: $paginated->total(),
            summary: $getConversationsSummary($request, $dateRange),
            available_tags: $filters->tags,
            available_finish_reasons: $filters->finish_reasons,
            available_reasoning_efforts: $filters->reasoning_efforts,
        );
    }
}
