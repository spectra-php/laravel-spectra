<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The stored trackable types (morph classes) that are users: those whose
 * model is authenticatable. Teams, conversations and other trackables are
 * left out, while apps with a custom or more than one user model (`Admin`,
 * `Customer`) keep working.
 */
class GetUserTrackableTypes
{
    /**
     * Create a new get user trackable types action instance.
     */
    public function __construct(
        private readonly GetAvailableTrackableTypes $getAvailableTrackableTypes,
    ) {}

    /**
     * Get every stored trackable type whose model is a user, sorted.
     *
     * @return array<int, string>
     */
    public function __invoke(): array
    {
        return array_values(array_filter(
            ($this->getAvailableTrackableTypes)(),
            fn (string $type) => is_a(Relation::getMorphedModel($type) ?? $type, Authenticatable::class, true),
        ));
    }
}
