<?php

declare(strict_types=1);

namespace Spectra\Actions\Scores;

use Illuminate\Database\Eloquent\Builder;
use Spectra\Data\ScoreEntry;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;

/**
 * The scores shown on a call, trace or conversation screen. A trace also
 * shows the scores given to its calls, and a conversation those given to its
 * calls and traces, newest first. None until the app runs the scores
 * migration.
 */
class GetScores
{
    /** Scores shown on one screen. */
    private const LIMIT = 200;

    /**
     * Get the scores of one call, trace or conversation.
     *
     * @return array<int, ScoreEntry>
     */
    public function __invoke(?string $requestId = null, ?string $traceId = null, ?string $conversationId = null): array
    {
        return rescue(
            fn () => SpectraScore::query()
                ->where(function (Builder $query) use ($requestId, $traceId, $conversationId): void {
                    if (filled($requestId)) {
                        $query->orWhere('request_id', $requestId);
                    }

                    if (filled($traceId)) {
                        $query->orWhere('trace_id', $traceId)
                            ->orWhereIn('request_id', SpectraRequest::query()->select('id')->where('trace_id', $traceId));
                    }

                    if (filled($conversationId)) {
                        $calls = SpectraRequest::query()->where('conversation_id', $conversationId);

                        $query->orWhere('conversation_id', $conversationId)
                            ->orWhereIn('request_id', $calls->clone()->select('id'))
                            ->orWhereIn('trace_id', $calls->clone()->whereNotNull('trace_id')->select('trace_id'));
                    }
                })
                ->latest()
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (SpectraScore $score) => ScoreEntry::fromModel($score))
                ->all(),
            [],
            report: false,
        );
    }
}
