<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Requests\BuildModelBreakdown;
use Spectra\Actions\Scores\GetScores;
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
 * the reply from its last. Side calls such as guardrails and query rewriters
 * are passed over when the turn has a call that looks like the user's own
 * exchange. A call without a turn number is a turn of its own. Each turn
 * carries the conversation's cost so far.
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
        private readonly GetScores $getScores,
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
            turns: $this->buildTurns($calls),
            models: ($this->buildModelBreakdown)(RequestAggregateQuery::make()->forConversation($conversationId)),
            tags: SpectraTag::query()
                ->whereHas('requests', fn ($requests) => $requests->where('conversation_id', $conversationId))
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            trackable: ($this->resolveSoleTrackable)(RequestAggregateQuery::make()->forConversation($conversationId)),
            scores: ($this->getScores)(conversationId: $conversationId),
        );
    }

    /**
     * Group the calls into turns with their prompt, reply, cost and running cost.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function buildTurns(Collection $calls): array
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
                $transcriptCalls = $this->pickTranscriptCalls(
                    $turnCalls->filter(fn (SpectraRequest $call) => in_array($call->model_type, ['text', null], true)),
                );

                return [
                    'turn' => $first->conversation_turn,
                    'started_at' => $this->resolveStartedAt($first)->format(self::ISO_MILLISECONDS),
                    'prompt' => $this->findFirstFilled($transcriptCalls, fn (SpectraRequest $call) => $this->messageTextExtractor->prompt($call->request)),
                    'reply' => $this->findFirstFilled($transcriptCalls->reverse(), fn (SpectraRequest $call) => $this->messageTextExtractor->reply($call)),
                    'request_count' => $turnCalls->count(),
                    'failed_count' => $turnCalls->filter(fn (SpectraRequest $call) => $call->isFailed())->count(),
                    'total_cost_in_cents' => $cost,
                    'running_cost_in_cents' => $runningCost,
                    'total_tokens' => $turnCalls->sum(fn (SpectraRequest $call) => $call->prompt_tokens + $call->completion_tokens),
                    'duration_ms' => $this->measureDurationMs($turnCalls),
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
     * Get the text calls most likely to be the exchange the user saw, in order.
     *
     * An app often makes side calls in the same turn: a guardrail or judge
     * that wraps the message and the history in its own prompt, a query
     * rewriter. Their prompts aren't what the user typed and their replies
     * aren't what the user read, so only the calls of the best kind count.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @return Collection<int, SpectraRequest>
     */
    private function pickTranscriptCalls(Collection $calls): Collection
    {
        $kinds = $calls->map(fn (SpectraRequest $call) => $this->rankTranscriptKind($call));
        $best = $kinds->max();

        return $calls->filter(fn (SpectraRequest $call, int $key) => $kinds[$key] === $best);
    }

    /**
     * Rank how surely the call is the user's exchange.
     *
     * 2 when it streams or offers tools, as an agent's reply does; 0 when it
     * asks for structured output, as a classifier, judge or extractor does;
     * 1 otherwise.
     */
    private function rankTranscriptKind(SpectraRequest $call): int
    {
        $request = is_array($call->request) ? $call->request : [];

        if ($this->isStructuredOutputRequest($request)) {
            return 0;
        }

        $isStreaming = ($request['stream'] ?? false) === true
            || (is_array($call->response) && ($call->response['streaming'] ?? false) === true);

        return $isStreaming || filled($request['tools'] ?? null) ? 2 : 1;
    }

    /**
     * Determine if the request asks for a JSON answer rather than a reply to read.
     *
     * @param  array<mixed>  $request
     */
    private function isStructuredOutputRequest(array $request): bool
    {
        $formats = [
            // OpenAI Chat Completions and the APIs that copy it (Mistral, Groq, xAI, OpenRouter, Cohere v2).
            $request['response_format']['type'] ?? null,
            // OpenAI Responses.
            $request['text']['format']['type'] ?? null,
            // Anthropic structured outputs, the beta and the current field.
            $request['output_format']['type'] ?? null,
            $request['output_config']['format']['type'] ?? null,
        ];

        foreach ($formats as $format) {
            if (in_array($format, ['json_schema', 'json_object'], true)) {
                return true;
            }
        }

        $generation = $request['generationConfig'] ?? null;

        // Gemini.
        if (is_array($generation) && (($generation['responseMimeType'] ?? null) === 'application/json' || isset($generation['responseSchema']) || isset($generation['responseJsonSchema']))) {
            return true;
        }

        // Ollama: "json" or a JSON schema.
        $format = $request['format'] ?? null;

        return $format === 'json' || is_array($format);
    }

    /**
     * Find the first non-empty text the reader returns for the calls, in their order.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @param  callable(SpectraRequest): ?string  $read
     */
    private function findFirstFilled(Collection $calls, callable $read): ?string
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
     * Measure the turn's duration in milliseconds, from its first call starting to its last call ending.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     */
    private function measureDurationMs(Collection $calls): int
    {
        $first = $calls->first();

        if ($first === null) {
            return 0;
        }

        $start = $this->resolveStartedAt($first);
        $end = $calls->max(fn (SpectraRequest $call) => $this->resolveStartedAt($call)->getPreciseTimestamp(3) + ($call->latency_ms ?? 0));

        return max(0, (int) round($end - $start->getPreciseTimestamp(3)));
    }

    /**
     * Get when the call started, falling back to when it was recorded.
     */
    private function resolveStartedAt(SpectraRequest $call): CarbonInterface
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
