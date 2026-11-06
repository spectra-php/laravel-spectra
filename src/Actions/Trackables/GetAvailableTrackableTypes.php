<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Spectra\Models\SpectraRequest;

/**
 * The trackable types (morph classes) that ever made a request, for the
 * trackable-type filter of the requests, traces, conversations, trackables
 * and costs screens.
 */
class GetAvailableTrackableTypes
{
    /**
     * Get every stored trackable type, sorted.
     *
     * @return array<int, string>
     */
    public function __invoke(): array
    {
        return SpectraRequest::query()
            ->whereNotNull('trackable_type')
            ->distinct()
            ->orderBy('trackable_type')
            ->pluck('trackable_type')
            ->map(fn (mixed $type) => (string) $type)
            ->all();
    }
}
