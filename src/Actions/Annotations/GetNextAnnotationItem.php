<?php

declare(strict_types=1);

namespace Spectra\Actions\Annotations;

use Spectra\Actions\Scores\GetScores;
use Spectra\Data\AnnotationReviewItem;
use Spectra\Enums\AnnotationStatus;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;

/**
 * The next trace to review in a queue, the one waiting longest, with what
 * each of its calls was asked and answered.
 */
class GetNextAnnotationItem
{
    /** Calls of a trace shown to the reviewer. */
    private const CALLS = 25;

    /**
     * Create a new get next annotation item action instance.
     */
    public function __construct(
        private readonly MessageTextExtractor $messages,
        private readonly GetScores $getScores,
    ) {}

    /**
     * Get the queue's next pending trace, or null when none is left.
     */
    public function __invoke(string $queue): ?AnnotationReviewItem
    {
        $item = SpectraAnnotationItem::query()
            ->where('queue', $queue)
            ->where('status', AnnotationStatus::Pending)
            ->oldest('created_at')
            ->first();

        return $item !== null ? $this->forItem($item) : null;
    }

    /**
     * The review view of one item.
     */
    public function forItem(SpectraAnnotationItem $item): AnnotationReviewItem
    {
        $calls = SpectraRequest::query()
            ->forTrace($item->trace_id)
            ->oldest('started_at')
            ->oldest('created_at')
            ->limit(self::CALLS)
            ->get()
            ->makeVisible(['request', 'response']);

        return new AnnotationReviewItem(
            id: $item->id,
            queue: $item->queue,
            trace_id: $item->trace_id,
            request_id: $item->request_id,
            origin: $calls->first()?->origin,
            queued_at: $item->created_at?->toIso8601String(),
            calls: $calls->map(fn (SpectraRequest $call) => [
                'id' => $call->id,
                'provider' => $call->provider,
                'model' => $call->model,
                'model_type' => $call->model_type,
                'status_code' => $call->status_code,
                'error_message' => $call->error_message,
                'latency_ms' => $call->latency_ms,
                'total_cost_in_cents' => $call->total_cost_in_cents,
                'prompt' => $this->messages->prompt($call->request),
                'reply' => $this->messages->reply($call),
                'is_queued_call' => $call->id === $item->request_id,
            ])->values()->all(),
            scores: ($this->getScores)(traceId: $item->trace_id),
        );
    }
}
