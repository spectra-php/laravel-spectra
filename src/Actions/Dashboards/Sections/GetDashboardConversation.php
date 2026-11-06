<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Data\Responses\DashboardConversationResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\MessageTextExtractor;

/**
 * One of the trackable's conversations for a shared dashboard, turn by turn. The
 * call bodies are read only on a dashboard that shows messages, and even then
 * only the user's message and the reply leave the server.
 */
class GetDashboardConversation
{
    /** Calls read; a longer conversation is cut. */
    private const LIMIT = 200;

    /** ISO 8601 with milliseconds, as the screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Create a new get dashboard conversation action instance.
     */
    public function __construct(
        private readonly MessageTextExtractor $messageTextExtractor,
    ) {}

    /**
     * Get the conversation, or null unless every call in it is the trackable's.
     */
    public function __invoke(DashboardScope $scope, string $conversationId, bool $showCosts, bool $showMessages): ?DashboardConversationResponse
    {
        if (! $scope->owns('conversation_id', $conversationId)) {
            return null;
        }

        $total = $scope->calls()->where('conversation_id', $conversationId)->count();

        $columns = [
            'id', 'provider', 'model', 'endpoint', 'model_type', 'conversation_turn', 'status_code', 'error_type', 'prompt_tokens', 'completion_tokens',
            'total_cost_in_cents', 'latency_ms', 'created_at', 'started_at',
        ];

        $calls = $scope->calls()
            ->where('conversation_id', $conversationId)
            ->select($showMessages ? [...$columns, 'request', 'response'] : $columns)
            ->orderByRaw('COALESCE(started_at, created_at)')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $turns = $calls
            ->groupBy(fn (SpectraRequest $call) => $call->conversation_turn === null ? "call:{$call->id}" : "turn:{$call->conversation_turn}")
            ->map(fn (Collection $turnCalls) => $this->turn($turnCalls, $showCosts, $showMessages))
            ->values()
            ->all();

        $starts = $calls->map(fn (SpectraRequest $call) => $this->startOf($call));

        return new DashboardConversationResponse(
            conversation_id: $conversationId,
            request_count: $total,
            turn_count: count($turns),
            failed_count: $calls->filter(fn (SpectraRequest $call) => $call->isFailed())->count(),
            total_cost_in_cents: $showCosts ? (float) $calls->filter(fn (SpectraRequest $call) => $call->isSuccessful())->sum('total_cost_in_cents') : null,
            total_tokens: $calls->sum(fn (SpectraRequest $call) => $call->prompt_tokens + $call->completion_tokens),
            started_at: $starts->min()?->format(self::ISO_MILLISECONDS),
            last_activity_at: $starts->max()?->format(self::ISO_MILLISECONDS),
            truncated: $total > $calls->count(),
            turns: $turns,
        );
    }

    /**
     * Build one turn's row from its calls.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @return array<string, mixed>
     */
    private function turn(Collection $calls, bool $showCosts, bool $showMessages): array
    {
        /** @var SpectraRequest $first */
        $first = $calls->first();
        $start = $this->startOf($first);
        $end = $calls->max(fn (SpectraRequest $call) => $this->startOf($call)->getPreciseTimestamp(3) + ($call->latency_ms ?? 0));
        $transcript = $showMessages ? $this->transcriptCalls($calls->filter(fn (SpectraRequest $call) => in_array($call->model_type, ['text', null], true))) : collect();

        return [
            'turn' => $first->conversation_turn,
            'started_at' => $start->format(self::ISO_MILLISECONDS),
            'prompt' => $this->firstFilled($transcript, fn (SpectraRequest $call) => $this->messageTextExtractor->prompt($call->request)),
            'reply' => $this->firstFilled($transcript->reverse(), fn (SpectraRequest $call) => $this->messageTextExtractor->reply($call)),
            'request_count' => $calls->count(),
            'failed_count' => $calls->filter(fn (SpectraRequest $call) => $call->isFailed())->count(),
            'total_cost_in_cents' => $showCosts ? (float) $calls->filter(fn (SpectraRequest $call) => $call->isSuccessful())->sum('total_cost_in_cents') : null,
            'total_tokens' => $calls->sum(fn (SpectraRequest $call) => $call->prompt_tokens + $call->completion_tokens),
            'duration_ms' => max(0, (int) round($end - $start->getPreciseTimestamp(3))),
        ];
    }

    /**
     * Pick the calls that are the user's exchange: a turn can also hold side
     * calls, such as a guardrail that asks for a JSON verdict, whose prompts
     * aren't what the user typed. A streamed call or one offering tools is the
     * surest, one asking for structured output the least.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @return Collection<int, SpectraRequest>
     */
    private function transcriptCalls(Collection $calls): Collection
    {
        $ranks = $calls->map(function (SpectraRequest $call): int {
            $request = is_array($call->request) ? $call->request : [];

            if ($this->asksForStructuredOutput($request)) {
                return 0;
            }

            $streamed = ($request['stream'] ?? false) === true
                || (is_array($call->response) && ($call->response['streaming'] ?? false) === true);

            return $streamed || filled($request['tools'] ?? null) ? 2 : 1;
        });
        $best = $ranks->max();

        return $calls->filter(fn (SpectraRequest $call, int $key) => $ranks[$key] === $best);
    }

    /**
     * Determine if the request asks for a JSON answer rather than a reply to read.
     *
     * @param  array<mixed>  $request
     */
    private function asksForStructuredOutput(array $request): bool
    {
        $formats = [
            $request['response_format']['type'] ?? null,
            $request['text']['format']['type'] ?? null,
            $request['output_format']['type'] ?? null,
            $request['output_config']['format']['type'] ?? null,
        ];

        foreach ($formats as $format) {
            if (in_array($format, ['json_schema', 'json_object'], true)) {
                return true;
            }
        }

        $generation = $request['generationConfig'] ?? null;

        if (is_array($generation) && (($generation['responseMimeType'] ?? null) === 'application/json' || isset($generation['responseSchema']))) {
            return true;
        }

        $format = $request['format'] ?? null;

        return $format === 'json' || is_array($format);
    }

    /**
     * Find the first non-empty text the reader returns for the calls, in their order.
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
     * Get when the call started, falling back to when it was recorded.
     */
    private function startOf(SpectraRequest $call): CarbonInterface
    {
        return $call->started_at ?? $call->created_at ?? Date::now();
    }
}
