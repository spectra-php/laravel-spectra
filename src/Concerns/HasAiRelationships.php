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
    /**
     * Get the AI requests this model made.
     */
    public function aiRequests(): MorphMany
    {
        return $this->morphMany(SpectraRequest::class, 'trackable');
    }

    /**
     * Get this model's AI budget, active or not.
     */
    public function aiBudget(): MorphOne
    {
        return $this->morphOne(SpectraBudget::class, 'budgetable');
    }

    /**
     * Get this model's AI budget when it is active.
     */
    public function activeAiBudget(): MorphOne
    {
        return $this->aiBudget()->where('is_active', true);
    }
}
