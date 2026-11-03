<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * Names and emails for the trackables on one page of results, loaded with
 * one query per trackable type instead of one per row.
 *
 * The stored type is the morph class, an alias when the app uses a morph
 * map, so it is resolved to its model class first; anything that isn't an
 * Eloquent model is skipped. Results are keyed by the stored
 * "{trackable_type}:{trackable_id}" so callers can match their rows.
 */
class ResolveTrackableMetadata
{
    /**
     * @param  Collection<int, SpectraRequest>  $pageItems
     * @return array<string, array<string, mixed>>
     */
    public function __invoke(Collection $pageItems): array
    {
        $idsByType = $pageItems
            ->filter(fn (SpectraRequest $item) => filled($item->trackable_type) && filled($item->trackable_id))
            ->groupBy('trackable_type')
            ->map(fn (Collection $items) => $items->pluck('trackable_id')->map(fn ($id) => (string) $id)->unique()->values());

        $metadata = [];

        foreach ($idsByType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $records = $class::query()->whereKey($ids->all())->get();

            foreach ($records as $record) {
                $metadata["{$type}:{$record->getKey()}"] = [
                    'name' => $record->name ?? null,
                    'email' => $record->email ?? null,
                ];
            }
        }

        return $metadata;
    }
}
