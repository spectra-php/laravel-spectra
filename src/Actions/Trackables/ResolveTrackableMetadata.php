<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;

class ResolveTrackableMetadata
{
    /**
     * @param  \Illuminate\Support\Collection<int, \Spectra\Models\SpectraRequest>  $pageItems
     * @return array<string, array<string, mixed>>
     */
    public function __invoke(Collection $pageItems): array
    {
        if ($pageItems->isEmpty()) {
            return [];
        }

        $idsByType = [];
        foreach ($pageItems as $item) {
            $type = $item->trackable_type;
            $id = (string) $item->trackable_id;

            if ($type === null || ! class_exists($type)) {
                continue;
            }

            if (! is_subclass_of($type, EloquentModel::class)) {
                continue;
            }

            $idsByType[$type][] = $id;
        }

        $metadata = [];
        foreach ($idsByType as $type => $ids) {
            /** @var class-string<EloquentModel> $type */
            $records = $type::query()
                ->whereIn((new $type)->getKeyName(), array_unique($ids))
                ->get();

            foreach ($records as $record) {
                $key = $type.':'.$record->getKey();
                $metadata[$key] = [
                    'name' => $record->name ?? null,
                    'email' => $record->email ?? null,
                ];
            }
        }

        return $metadata;
    }
}
