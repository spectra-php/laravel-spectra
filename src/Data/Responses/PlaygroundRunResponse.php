<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The result of one Playground run: the model's reply or the provider's error,
 * the usage, cost and latency, and the id of the row it was saved as, which is
 * null when recording is paused or requests aren't stored.
 */
readonly class PlaygroundRunResponse extends DataTransferObject
{
    /**
     * Create a new playground run response instance.
     *
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public bool $succeeded,
        public ?int $status_code,
        public ?string $error_type,
        public ?string $error_message,
        public ?string $reply,
        public ?array $response,
        public string $model,
        public ?string $finish_reason,
        public bool $has_tool_calls,
        public int $prompt_tokens,
        public int $completion_tokens,
        public float $total_cost_in_cents,
        public ?int $latency_ms,
        public ?string $request_id,
    ) {}
}
