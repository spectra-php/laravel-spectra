<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Models\SpectraRequest;

/**
 * The result of one Playground run: the model's reply or the provider's error,
 * the usage, cost and latency, and the id of the row it was saved as, which is
 * null when recording is paused or requests aren't stored. `number` is its place
 * among the runs on the screen, once it is read back from its row.
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
        public ?int $number = null,
    ) {}

    /**
     * Create a run's result from the row it was saved as, its model id, its reply
     * text and its place among the runs on the screen.
     */
    public static function fromModel(SpectraRequest $run, string $model, ?string $reply, ?int $number = null): self
    {
        return new self(
            succeeded: SpectraRequest::isSuccessfulOutcome($run->status_code, $run->error_type),
            status_code: $run->status_code,
            error_type: $run->error_type,
            error_message: $run->error_message,
            reply: $reply,
            response: is_array($run->response) ? $run->response : null,
            model: $model,
            finish_reason: $run->finish_reason,
            has_tool_calls: $run->has_tool_calls,
            prompt_tokens: $run->prompt_tokens,
            completion_tokens: $run->completion_tokens,
            total_cost_in_cents: $run->total_cost_in_cents,
            latency_ms: $run->latency_ms,
            request_id: $run->id,
            number: $number,
        );
    }
}
