<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Data\ScoreEntry;

/**
 * One conversation as a transcript: its totals and its turns in order, each
 * with the user's message, the model's reply, its calls and the cost so far.
 * The two storage switches say why a turn may have no text. Until the trace
 * migrations run the transcript can't be built, and `migrated` says so.
 */
readonly class ConversationDetailsResponse extends DataTransferObject
{
    /**
     * Create a new conversation details response instance.
     *
     * @param  array<int, array<string, mixed>>  $turns
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>|null  $trackable
     * @param  array<int, ScoreEntry>  $scores
     */
    public function __construct(
        public string $conversation_id,
        public int $request_count,
        public int $turn_count,
        public int $failed_count,
        public float $total_cost_in_cents,
        public int $total_tokens,
        public ?string $started_at,
        public ?string $last_activity_at,
        public bool $truncated,
        public bool $stores_prompts,
        public bool $stores_responses,
        public array $turns,
        public array $models,
        public array $tags,
        public ?array $trackable,
        public array $scores = [],
        public bool $migrated = true,
    ) {}

    /**
     * Build the empty response the screen gets until the trace migrations run.
     */
    public static function unmigrated(string $conversationId): self
    {
        return new self(
            conversation_id: $conversationId,
            request_count: 0,
            turn_count: 0,
            failed_count: 0,
            total_cost_in_cents: 0.0,
            total_tokens: 0,
            started_at: null,
            last_activity_at: null,
            truncated: false,
            stores_prompts: (bool) config('spectra.storage.store_prompts', true),
            stores_responses: (bool) config('spectra.storage.store_responses', true),
            turns: [],
            models: [],
            tags: [],
            trackable: null,
            migrated: false,
        );
    }
}
