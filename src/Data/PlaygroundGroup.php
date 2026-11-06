<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One row of the Playground's start screen: the runs of one stored call or one
 * new request. `open_id` is the call a click opens, the original if it is still
 * there, and `prompt` is that call's. `latest` is the newest run.
 */
readonly class PlaygroundGroup extends DataTransferObject
{
    /**
     * Create a new playground group instance.
     */
    public function __construct(
        public string $open_id,
        public ?string $prompt,
        public int $run_count,
        public PlaygroundCall $latest,
    ) {}
}
