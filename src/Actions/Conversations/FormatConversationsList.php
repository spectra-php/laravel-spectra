<?php

declare(strict_types=1);

namespace Spectra\Actions\Conversations;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Shapes a page of conversation totals into the rows the conversations
 * screen shows, with the models each used in the order it first used them
 * and its trackable when it has only one.
 *
 * The extras come from one grouped query for the whole page, over each
 * conversation's calls in any date range.
 */
class FormatConversationsList
{
    /** ISO 8601 with milliseconds, as the screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Create a new format conversations list action instance.
     */
    public function __construct(
        private readonly ResolveTrackableMetadata $resolveTrackableMetadata,
    ) {}

    /**
     * Get the page of conversation totals as rows, with their models and sole trackable.
     *
     * @param  LengthAwarePaginator<int, SpectraRequest>  $paginated
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(LengthAwarePaginator $paginated): array
    {
        /** @var Collection<int, SpectraRequest> $conversations */
        $conversations = collect($paginated->items());

        if ($conversations->isEmpty()) {
            return [];
        }

        $callsByConversation = RequestAggregateQuery::make()
            ->forConversations($conversations->pluck('conversation_id')->map(fn ($id) => (string) $id)->all())
            ->groupByColumns('conversation_id', 'provider', 'model', 'trackable_type', 'trackable_id')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->get()
            ->sortBy(fn (SpectraRequest $call) => (string) $call->first_started_at)
            ->groupBy('conversation_id');

        $metadata = ($this->resolveTrackableMetadata)($callsByConversation->flatten(1));

        return $conversations->map(function (SpectraRequest $conversation) use ($callsByConversation, $metadata) {
            /** @var Collection<int, SpectraRequest> $calls */
            $calls = $callsByConversation->get((string) $conversation->conversation_id, collect());

            return [
                'conversation_id' => (string) $conversation->conversation_id,
                'request_count' => $conversation->request_count,
                'turn_count' => $conversation->turn_count,
                'failed_count' => $conversation->failed_count,
                'total_cost_in_cents' => $conversation->cost,
                'total_tokens' => $conversation->tokens,
                'started_at' => $this->format($conversation->first_started_at),
                'last_activity_at' => $this->format($conversation->last_started_at),
                'models' => $calls
                    ->unique(fn (SpectraRequest $call) => "{$call->provider}|{$call->model}")
                    ->map(fn (SpectraRequest $call) => [
                        'provider' => $call->provider,
                        'provider_display_name' => $call->provider_display_name,
                        'model' => $call->model,
                    ])
                    ->values()
                    ->all(),
                'trackable' => $this->findSoleTrackable($calls, $metadata),
            ];
        })->values()->all();
    }

    /**
     * Get the conversation's trackable when all its calls belong to one, or null.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @param  array<string, array<string, mixed>>  $metadata
     * @return array<string, mixed>|null
     */
    private function findSoleTrackable(Collection $calls, array $metadata): ?array
    {
        $trackables = $calls
            ->filter(fn (SpectraRequest $call) => filled($call->trackable_type) && filled($call->trackable_id))
            ->unique(fn (SpectraRequest $call) => "{$call->trackable_type}:{$call->trackable_id}");

        if ($trackables->count() !== 1) {
            return null;
        }

        /** @var SpectraRequest $call */
        $call = $trackables->first();
        $key = "{$call->trackable_type}:{$call->trackable_id}";

        return [
            'type' => $call->trackable_type,
            'id' => $call->trackable_id,
            'name' => $metadata[$key]['name'] ?? null,
            'email' => $metadata[$key]['email'] ?? null,
        ];
    }

    /**
     * Format a stored timestamp as ISO 8601 with milliseconds, or null when blank.
     */
    private function format(mixed $value): ?string
    {
        return blank($value) ? null : Date::parse((string) $value)->format(self::ISO_MILLISECONDS);
    }
}
