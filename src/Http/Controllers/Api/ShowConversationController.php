<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Conversations\GetConversationDetails;
use Spectra\Data\Responses\ConversationDetailsResponse;
use Spectra\Support\Query\TraceSchema;

/**
 * GET /api/conversations/{conversationId}: the conversation transcript.
 */
class ShowConversationController extends BaseApiController
{
    /** Calls the transcript shows; the requests list has the rest. */
    private const CALL_LIMIT = 200;

    /**
     * Get the conversation transcript, or a 404 when it has no calls.
     */
    public function __invoke(string $conversationId, GetConversationDetails $getConversationDetails, TraceSchema $traceSchema): ConversationDetailsResponse
    {
        if (! $traceSchema->hasTraceColumns()) {
            return ConversationDetailsResponse::unmigrated($conversationId);
        }

        return $getConversationDetails($conversationId, self::CALL_LIMIT) ?? abort(404);
    }
}
