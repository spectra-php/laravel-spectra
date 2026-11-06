<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * What one call said, on a shared dashboard that shows messages: the user's last
 * message and the reply, never the system prompt, tools or the raw body.
 */
readonly class DashboardCallMessagesResponse extends DataTransferObject
{
    /**
     * Create a new dashboard call messages response instance.
     */
    public function __construct(
        public ?string $model,
        public ?string $prompt,
        public ?string $reply,
    ) {}
}
