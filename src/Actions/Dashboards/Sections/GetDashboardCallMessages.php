<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Spectra\Data\Responses\DashboardCallMessagesResponse;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\MessageTextExtractor;

/**
 * What one of the trackable's calls said: the user's last message and the reply.
 * The extractor reads only those, so the system prompt, tools and raw body never
 * leave the server.
 */
class GetDashboardCallMessages
{
    /**
     * Create a new get dashboard call messages action instance.
     */
    public function __construct(
        private readonly MessageTextExtractor $messageTextExtractor,
    ) {}

    /**
     * Get the call's messages, or null when it isn't the trackable's.
     */
    public function __invoke(DashboardScope $scope, string $id): ?DashboardCallMessagesResponse
    {
        $call = $scope->calls()->select(['id', 'provider', 'model', 'model_type', 'endpoint', 'request', 'response'])->find($id);

        if ($call === null) {
            return null;
        }

        return new DashboardCallMessagesResponse(
            model: $call->model,
            prompt: $this->messageTextExtractor->prompt($call->request),
            reply: $this->messageTextExtractor->reply($call),
        );
    }
}
