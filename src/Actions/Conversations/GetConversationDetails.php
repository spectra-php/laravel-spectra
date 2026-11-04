<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Requests\BuildModelBreakdown;
use Spectra\Actions\Trackables\ResolveSoleTrackable;
use Spectra\Data\Responses\ConversationDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * A conversation as the transcript screen shows it: its calls grouped into
 * turns, in the order they started.
 *
 * Calls with the same turn number form one turn, so an agent's tool loop
 * reads as one exchange: the prompt comes from the turn's first text call,
 * the reply from its last. A call without a turn number is a turn of its
 * own. Each turn carries the conversation's cost so far.
 */
class GetConversationDetails
{
    /** ISO 8601 with milliseconds, as the screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Create a new get conversation details action instance.
     */
    public function __construct(
        private readonly MessageTextExtractor $messageTextExtractor,
        private readonly BuildModelBreakdown $buildModelBreakdown,
        private readonly ResolveSoleTrackable $resolveSoleTrackable,
    ) {}

    /**
     * Get the conversation's totals, turns, models and tags, or null when it has no calls.
     */
    public function __invoke(string $conversationId, int $limit = 200): ?ConversationDetailsResponse
    {
        $totals = RequestAggregateQuery::make()->forConversation($conversationId)->conversationTotals()->first();

        if ($totals === null || $totals->request_count === 0) {
            return null;
        }

        $calls = SpectraRequest::query()
            ->where('conversation_id', $conversationId)
            ->select([
                'id', 'provider', 'model', 'model_type', 'endpoint', 'conversation_turn', 'trace_id', 'status_code',
                'finish_reason', 'prompt_tokens', 'completion_tokens', 'total_cost_in_cents', 'latency_ms',
                'created_at', 'started_at', 'request', 'response',
            ])
            ->orderByRaw('COALESCE(started_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return new ConversationDetailsResponse(
            conversation_id: $conversationId,
            request_count: $totals->request_count,
            turn_count: $totals->turn_count,
            failed_count: $totals->failed_count,
            total_cost_in_cents: $totals->cost,
            total_tokens: $totals->tokens,
            started_at: $this->format($totals->first_started_at),
            last_activity_at: $this->format($totals->last_started_at),
            truncated: $totals->request_count > $calls->count(),
            stores_prompts: (bool) config('spectra.storage.store_prompts', true),
            stores_responses: (bool) config('spectra.storage.store_responses', true),
            turns: $this->turns($calls),
            models: ($this->buildModelBreakdown)(RequestAggregateQuery::make()->forConversation($conversationId)),
            tags: SpectraTag::query()
                ->whereHas('requests', fn ($requests) => $requests->where('conversation_id', $conversationId))
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            trackable: ($this->resolveSoleTrackable)(RequestAggregateQuery::make()->forConversation($conversationId)),
        );
    }

    /**
     * Group the calls into turns with their prompt, reply, cost and running cost.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function turns(Collection $calls): array
    {
        $runningCost = 0.0;

        return $calls
            ->groupBy(fn (SpectraRequest $call) => $call->conversation_turn === null ? "call:{$call->id}" : "turn:{$call->conversation_turn}")
            ->map(function (Collection $turnCalls) use (&$runningCost) {
                /** @var Collection<int, SpectraRequest> $turnCalls */
                $cost = (float) $turnCalls->filter(fn (SpectraRequest $call) => $call->isSuccessful())->sum('total_cost_in_cents');
                $runningCost += $cost;

                /** @var SpectraRequest $first */
                $first = $turnCalls->first();
                $textCalls = $turnCalls->filter(fn (SpectraRequest $call) => in_array($call->model_type, ['text', null], true));

                return [
                    'turn' => $first->conversation_turn,
                    'started_at' => $this->startOf($first)->format(self::ISO_MILLISECONDS),
                    'prompt' => $this->firstFilled($textCalls, fn (SpectraRequest $call) => $this->messageTextExtractor->prompt($call->request)),
                    'reply' => $this->firstFilled($textCalls->reverse(), fn (SpectraRequest $call) => $this->messageTextExtractor->reply($call)),
                    'request_count' => $turnCalls->count(),
                    'failed_count' => $turnCalls->filter(fn (SpectraRequest $call) => $call->isFailed())->count(),
                    'total_cost_in_cents' => $cost,
                    'running_cost_in_cents' => $runningCost,
                    'total_tokens' => $turnCalls->sum(fn (SpectraRequest $call) => $call->prompt_tokens + $call->completion_tokens),
                    'duration_ms' => $this->durationMs($turnCalls),
                    'trace_id' => $first->trace_id,
                    'calls' => $turnCalls->map(fn (SpectraRequest $call) => [
                        'id' => $call->id,
                        'provider' => $call->provider,
                        'provider_display_name' => $call->provider_display_name,
                        'model' => $call->model,
                        'model_type' => $call->model_type,
                        'status_code' => $call->status_code,
                        'is_error' => $call->isFailed(),
                        'finish_reason' => $call->finish_reason,
                        'total_tokens' => $call->prompt_tokens + $call->completion_tokens,
                        'total_cost_in_cents' => $call->total_cost_in_cents,
                        'latency_ms' => $call->latency_ms,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The first non-empty text the reader finds in the calls, in their order.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @param  callable(SpectraRequest): ?string  $read
     */
    private function firstFilled(Collection $calls, callable $read): ?string
    {
        foreach ($calls as $call) {
            $text = $read($call);

            if (filled($text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * From the turn's first call starting to its last call ending.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     */
    private function durationMs(Collection $calls): int
    {
        $first = $calls->first();

        if ($first === null) {
            return 0;
        }

        $start = $this->startOf($first);
        $end = $calls->max(fn (SpectraRequest $call) => $this->startOf($call)->getPreciseTimestamp(3) + ($call->latency_ms ?? 0));

        return max(0, (int) round($end - $start->getPreciseTimestamp(3)));
    }

    /**
     * Get when the call started, falling back to when it was recorded.
     */
    private function startOf(SpectraRequest $call): CarbonInterface
    {
        return $call->started_at ?? $call->created_at ?? Date::now();
    }

    /**
     * Format a stored timestamp as ISO 8601 with milliseconds, or null when blank.
     */
    private function format(mixed $value): ?string
    {
        return blank($value) ? null : Date::parse((string) $value)->format(self::ISO_MILLISECONDS);
    }
}
