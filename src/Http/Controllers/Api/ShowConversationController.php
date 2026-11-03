<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Conversations\GetConversationDetails;
use Spectra\Data\Responses\ConversationDetailsResponse;

/**
 * GET /api/conversations/{conversationId}: the conversation transcript.
 */
class ShowConversationController extends BaseApiController
{
    /** Calls the transcript shows; the requests list has the rest. */
    private const CALL_LIMIT = 200;

    public function __invoke(string $conversationId, GetConversationDetails $getConversationDetails): ConversationDetailsResponse
    {
        return $getConversationDetails($conversationId, self::CALL_LIMIT) ?? abort(404);
    }
}
