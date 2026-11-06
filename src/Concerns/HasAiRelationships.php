<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Relations\StringKeyedMorphMany;
use Spectra\Support\Relations\StringKeyedMorphOne;

/**
 * The Eloquent relations from a trackable model to its Spectra rows: the
 * requests it made and its (active) budget, keyed by the model's morph class.
 * Spectra's morph id columns are strings, so the relations compare this
 * model's key as a string whether it is an integer, a UUID or a ULID.
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
        $requests = $this->newRelatedInstance(SpectraRequest::class);
        [$type, $id] = $this->getMorphs('trackable', null, null);

        return new StringKeyedMorphMany($requests->newQuery(), $this, $requests->qualifyColumn($type), $requests->qualifyColumn($id), $this->getKeyName());
    }

    /**
     * Get this model's AI budget, active or not.
     */
    public function aiBudget(): MorphOne
    {
        $budgets = $this->newRelatedInstance(SpectraBudget::class);
        [$type, $id] = $this->getMorphs('budgetable', null, null);

        return new StringKeyedMorphOne($budgets->newQuery(), $this, $budgets->qualifyColumn($type), $budgets->qualifyColumn($id), $this->getKeyName());
    }

    /**
     * Get this model's AI budget when it is active.
     */
    public function activeAiBudget(): MorphOne
    {
        return $this->aiBudget()->where('is_active', true);
    }
}
