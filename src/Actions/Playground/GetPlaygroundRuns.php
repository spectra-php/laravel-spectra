<?php

declare(strict_types=1);

namespace Spectra\Actions\Playground;

use Illuminate\Database\Eloquent\Builder;
use Spectra\Data\Responses\PlaygroundRunResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * The saved runs that belong together on a Playground screen: those started from
 * a stored call, and those of a new request's session. The tag narrows the rows
 * first, because the metadata column has no index.
 */
class GetPlaygroundRuns
{
    /** Runs brought back, since each one carries a whole response. */
    private const LIMIT = 20;

    /**
     * Create a new get playground runs action instance.
     */
    public function __construct(
        private readonly PlaygroundClient $client,
        private readonly MessageTextExtractor $textExtractor,
    ) {}

    /**
     * Get the latest runs, newest first and numbered from the oldest, and how many there are in all.
     *
     * @return array{runs: array<int, PlaygroundRunResponse>, count: int}
     */
    public function __invoke(string $sourceId, ?string $sessionId): array
    {
        $query = SpectraRequest::query()
            ->withTag(PlaygroundClient::TAG)
            ->where(fn (Builder $query) => $query
                ->where('metadata->'.PlaygroundClient::SOURCE_METADATA_KEY, $sourceId)
                ->when($sessionId !== null, fn (Builder $query) => $query->orWhere('metadata->'.PlaygroundClient::SESSION_METADATA_KEY, $sessionId)));

        $count = (clone $query)->count();

        return [
            'count' => $count,
            'runs' => $query
                // UUIDv7 ids follow the order runs were sent in; created_at only has whole seconds.
                ->latest('id')
                ->limit(self::LIMIT)
                ->get()
                ->values()
                ->map(fn (SpectraRequest $run, int $index): PlaygroundRunResponse => PlaygroundRunResponse::fromModel(
                    $run,
                    $this->client->modelOf($run),
                    rescue(fn () => $this->textExtractor->reply($run), report: false),
                    $count - $index,
                ))
                ->all(),
        ];
    }
}
