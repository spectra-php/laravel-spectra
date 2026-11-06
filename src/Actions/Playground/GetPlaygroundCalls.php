<?php

declare(strict_types=1);

namespace Spectra\Actions\Playground;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spectra\Data\PlaygroundCall;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * The recent calls a Playground session can start from: text calls with their
 * request body stored, newest first. The Playground's own runs are left out,
 * or they would soon push out the calls of the app.
 */
class GetPlaygroundCalls
{
    /** Calls listed. */
    private const LIMIT = 15;

    /** Rows read to find them, since not every text call went to a chat endpoint. */
    private const SCAN_LIMIT = 100;

    /**
     * Create a new get playground calls action instance.
     */
    public function __construct(
        private readonly PlaygroundClient $client,
        private readonly MessageTextExtractor $textExtractor,
    ) {}

    /**
     * Get the recent calls that can be opened in the Playground.
     *
     * @return Collection<int, PlaygroundCall>
     */
    public function __invoke(): Collection
    {
        return SpectraRequest::query()
            // A call that failed before its handler ran has no model type.
            ->where(fn (Builder $query) => $query->where('model_type', 'text')->orWhereNull('model_type'))
            ->whereIn('provider', $this->client->providers())
            ->whereNotNull('request')
            ->whereDoesntHave('tags', fn (Builder $query) => $query->where('name', PlaygroundClient::TAG))
            ->latest('created_at')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->filter(fn (SpectraRequest $call): bool => $this->client->supports($call))
            ->take(self::LIMIT)
            ->map(fn (SpectraRequest $call): PlaygroundCall => PlaygroundCall::fromModel($call, $this->textExtractor->prompt($call->request), null))
            ->values();
    }
}
