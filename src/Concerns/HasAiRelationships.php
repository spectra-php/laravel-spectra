<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;

/**
 * The Eloquent relations from a trackable model to its Spectra rows: the
 * requests it made and its (active) budget, keyed by the model's morph class.
 *
 * @phpstan-require-extends Model
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
