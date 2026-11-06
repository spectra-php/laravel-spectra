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
        $types = [];
        $last = null;

        // One index lookup per type: a type is the next value after the last
        // one. DISTINCT walks every row of the index on PostgreSQL.
        do {
            $next = SpectraRequest::query()
                ->whereNotNull('trackable_type')
                ->when($last !== null, fn ($query) => $query->where('trackable_type', '>', $last))
                ->orderBy('trackable_type')
                ->value('trackable_type');

            if ($next !== null) {
                $types[] = $last = (string) $next;
            }
        } while ($next !== null);

        return $types;
    }
}
