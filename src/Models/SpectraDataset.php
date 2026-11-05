<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of examples to run AI code on: inputs, and the outputs
 * expected for them. Filled with Spectra::dataset() or from a call's page.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class SpectraDataset extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Get the dataset's examples.
     *
     * @return HasMany<SpectraDatasetExample, $this>
     */
    public function examples(): HasMany
    {
        return $this->hasMany(SpectraDatasetExample::class, 'dataset_id');
    }
}
