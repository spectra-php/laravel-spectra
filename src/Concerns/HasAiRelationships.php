<?php

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiRelationships
{
    public function aiRequests(): MorphMany
    {
        return $this->morphMany(SpectraRequest::class, 'trackable');
    }

    public function aiBudget(): MorphOne
    {
        return $this->morphOne(SpectraBudget::class, 'budgetable');
    }

    public function activeAiBudget(): MorphOne
    {
        return $this->aiBudget()->where('is_active', true);
    }
}
