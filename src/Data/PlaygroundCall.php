<?php

declare(strict_types=1);

namespace Spectra\Data;

use Spectra\Models\SpectraRequest;

/**
 * A stored call as the Playground shows it: what it asked and answered, and
 * the numbers a run is compared with.
 */
readonly class PlaygroundCall extends DataTransferObject
{
    /**
     * Create a new playground call instance.
     */
    public function __construct(
        public string $id,
        public string $provider,
        public ?string $provider_display_name,
        public string $model,
        public ?string $endpoint,
        public ?string $prompt,
        public ?string $reply,
        public ?int $status_code,
        public ?string $error_type,
        public ?string $error_message,
        public ?string $finish_reason,
        public int $prompt_tokens,
        public int $completion_tokens,
        public float $total_cost_in_cents,
        public ?int $latency_ms,
        public bool $is_streaming,
        public ?string $created_at_human,
        public ?string $formatted_created_at,
    ) {}

    /**
     * Create a playground call from a stored request and its prompt and reply text.
     */
    public static function fromModel(SpectraRequest $call, ?string $prompt, ?string $reply): self
    {
        return new self(
            id: $call->id,
            provider: $call->provider,
            provider_display_name: $call->provider_display_name,
            model: $call->model,
            endpoint: $call->endpoint,
            prompt: $prompt,
            reply: $reply,
            status_code: $call->status_code,
            error_type: $call->error_type,
            error_message: $call->error_message,
            finish_reason: $call->finish_reason,
            prompt_tokens: $call->prompt_tokens,
            completion_tokens: $call->completion_tokens,
            total_cost_in_cents: $call->total_cost_in_cents,
            latency_ms: $call->latency_ms,
            is_streaming: $call->is_streaming,
            created_at_human: $call->created_at_human,
            formatted_created_at: $call->formatted_created_at,
        );
    }
}
