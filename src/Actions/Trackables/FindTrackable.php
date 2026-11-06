<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Database\Eloquent\Model;

/**
 * The trackable model itself, from a stored type and id. Anything that doesn't
 * resolve to an Eloquent model is never instantiated.
 */
class FindTrackable
{
    /**
     * Create a new find trackable action instance.
     */
    public function __construct(
        private readonly ResolveTrackableClass $resolveTrackableClass,
    ) {}

    /**
     * Find the trackable, or null when its type or row doesn't exist.
     */
    public function __invoke(string $type, string $id): ?Model
    {
        $class = ($this->resolveTrackableClass)($type);

        return $class === null ? null : $class::query()->find($id);
    }
}
