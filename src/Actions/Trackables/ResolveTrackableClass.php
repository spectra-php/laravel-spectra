<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The Eloquent model behind a stored trackable type. The type is the morph
 * class, which is an alias when the app uses a morph map; anything that doesn't
 * resolve to an Eloquent model has no class.
 */
class ResolveTrackableClass
{
    /**
     * Get the model class of the trackable type, or null when it has none.
     *
     * @return class-string<Model>|null
     */
    public function __invoke(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class;
    }
}
