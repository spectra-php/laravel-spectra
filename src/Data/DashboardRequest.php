<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One call in a dashboard's recent requests, under the keys the Overview's list
 * reads: what ran, how much it used and whether it worked. It never holds the
 * call's id, prompt, response, metadata, trace or error details.
 */
readonly class DashboardRequest extends DataTransferObject
{
    /**
     * Create a new dashboard request instance.
     */
    public function __construct(
        public ?string $provider,
        public ?string $provider_display_name,
        public ?string $model,
        public ?string $model_type,
        public int $prompt_tokens,
        public int $completion_tokens,
        public int $image_count,
        public int $video_count,
        public int $input_characters,
        public float $duration_seconds,
        public ?int $status_code,
        public bool $failed,
        public ?string $created_at_human,
    ) {}
}
