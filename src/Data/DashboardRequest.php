<?php

declare(strict_types=1);

namespace Spectra\Data;

use Spectra\Models\SpectraRequest;

/**
 * One call in a dashboard's recent requests, under the keys the Overview's list
 * reads: what ran, how much it used and whether it worked. It never holds the
 * prompt, response, metadata, trace or error details, and its id only on a page
 * that can open it.
 */
readonly class DashboardRequest extends DataTransferObject
{
    /** The columns fromModel() reads. */
    public const COLUMNS = [
        'id', 'provider', 'model', 'model_type', 'prompt_tokens', 'completion_tokens',
        'image_count', 'video_count', 'input_characters', 'duration_seconds',
        'status_code', 'error_type', 'created_at',
    ];

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
        public ?string $id = null,
    ) {}

    /**
     * Build a row from a call selected with COLUMNS.
     */
    public static function fromModel(SpectraRequest $call, bool $withId = false): self
    {
        return new self(
            provider: $call->provider,
            provider_display_name: $call->provider_display_name,
            model: $call->model,
            model_type: $call->model_type,
            prompt_tokens: $call->prompt_tokens,
            completion_tokens: $call->completion_tokens,
            image_count: $call->image_count ?? 0,
            video_count: $call->video_count ?? 0,
            input_characters: $call->input_characters ?? 0,
            duration_seconds: $call->duration_seconds ?? 0.0,
            status_code: $call->status_code,
            failed: $call->isFailed(),
            created_at_human: $call->created_at_human,
            id: $withId ? $call->id : null,
        );
    }
}
