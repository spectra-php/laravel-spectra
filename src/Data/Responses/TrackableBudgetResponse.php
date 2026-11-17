<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\TrackableBudgetLimit;
use Spectra\Models\SpectraBudget;

/**
 * The budget card on a trackable's page: whether a budget exists and is on,
 * whether it blocks at a limit, its limits with their usage, the providers
 * and models it is restricted to, and whether the user may change it.
 */
readonly class TrackableBudgetResponse extends DataTransferObject
{
    /**
     * Create a new trackable budget response instance.
     *
     * @param  Collection<int, TrackableBudgetLimit>  $limits
     * @param  array<int, string>  $allowed_providers
     * @param  array<int, string>  $allowed_models
     */
    public function __construct(
        public bool $exists,
        public bool $is_active,
        public bool $hard_limit,
        public Collection $limits,
        public array $allowed_providers,
        public array $allowed_models,
        public bool $can_manage,
    ) {}

    /**
     * Create the response for a trackable's budget, or for a trackable that has none.
     *
     * @param  Collection<int, TrackableBudgetLimit>  $limits
     */
    public static function fromBudget(?SpectraBudget $budget, Collection $limits, bool $canManage): self
    {
        return new self(
            exists: $budget !== null,
            is_active: $budget !== null && $budget->is_active,
            hard_limit: $budget === null || $budget->hard_limit,
            limits: $limits,
            allowed_providers: $budget->allowed_providers ?? [],
            allowed_models: $budget->allowed_models ?? [],
            can_manage: $canManage,
        );
    }
}
