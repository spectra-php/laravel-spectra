<?php

declare(strict_types=1);

namespace Spectra\Actions\Playground;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spectra\Data\PlaygroundCall;
use Spectra\Data\PlaygroundGroup;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * What the Playground's start screen lists: the requests sent from it, with the
 * runs of one stored call or one new request grouped into a row, newest first.
 * The app's own calls aren't listed: they open from their page in the requests list.
 */
class GetPlaygroundGroups
{
    /** Rows listed. */
    private const LIMIT = 20;

    /** Latest runs read to group them. The metadata that groups them can't be grouped in SQL on every driver. */
    private const SCAN_LIMIT = 1000;

    /**
     * Create a new get playground groups action instance.
     */
    public function __construct(
        private readonly PlaygroundClient $client,
        private readonly MessageTextExtractor $textExtractor,
    ) {}

    /**
     * Get the latest groups of runs.
     *
     * @return Collection<int, PlaygroundGroup>
     */
    public function __invoke(): Collection
    {
        $newest = [];
        $counts = [];

        // Newest first, so the first run seen of a group is its latest.
        foreach ($this->scan() as $run) {
            $key = $this->client->sessionOf($run) ?? $this->client->sourceOf($run) ?? $run->id;
            $newest[$key] ??= $run;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $newest = array_slice($newest, 0, self::LIMIT, true);

        $latestRuns = SpectraRequest::query()
            ->findMany(array_map(fn (SpectraRequest $run): string => $run->id, array_values($newest)))
            ->keyBy('id');

        // A new request's runs share a session and have no stored call to open.
        $originals = SpectraRequest::query()
            ->findMany(array_values(array_filter(array_map(fn (SpectraRequest $run): ?string => $this->client->sessionOf($run) === null ? $this->client->sourceOf($run) : null, $newest))))
            ->keyBy('id');

        $groups = [];

        foreach ($newest as $key => $run) {
            $latest = $latestRuns->get($run->id);

            if ($latest === null) {
                continue;
            }

            $original = $this->client->sessionOf($latest) === null ? $originals->get($this->client->sourceOf($latest)) : null;

            $groups[] = new PlaygroundGroup(
                open_id: $original->id ?? $latest->id,
                prompt: $this->textExtractor->prompt(($original ?? $latest)->request),
                run_count: $counts[$key],
                latest: PlaygroundCall::fromModel($latest, $this->textExtractor->prompt($latest->request), null),
            );
        }

        return collect($groups);
    }

    /**
     * Get the ids and metadata of the latest runs, newest first.
     *
     * @return EloquentCollection<int, SpectraRequest>
     */
    private function scan(): EloquentCollection
    {
        return SpectraRequest::query()
            ->withTag(PlaygroundClient::TAG)
            // UUIDv7 ids follow the order runs were sent in; created_at only has whole seconds.
            ->latest('id')
            ->limit(self::SCAN_LIMIT)
            ->get(['id', 'metadata']);
    }
}
