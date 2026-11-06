<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The Eloquent models behind a shared dashboard's stored trackable type and id.
 * Anything that doesn't resolve to an Eloquent model is never instantiated.
 */
class DashboardTrackables
{
    /**
     * Get the model class of a stored type, which is the morph class and so an
     * alias when the app uses a morph map, or null when it has none.
     *
     * @return class-string<Model>|null
     */
    public function classFor(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class;
    }

    /**
     * Find the trackable, or null when its type or row doesn't exist.
     */
    public function find(string $type, string $id): ?Model
    {
        $class = $this->classFor($type);

        return $class === null ? null : $class::query()->find($id);
    }
}
