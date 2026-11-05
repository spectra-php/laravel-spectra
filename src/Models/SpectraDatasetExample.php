<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One example of a dataset: the input an evaluation runs the AI code on,
 * the output expected (when there is one), and any metadata.
 *
 * @property string $id
 * @property string $dataset_id
 * @property mixed $input
 * @property mixed $expected
 * @property array<string, mixed>|null $metadata
 * @property string|null $source_request_id
 * @property CarbonInterface|null $created_at
 */
class SpectraDatasetExample extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'input' => 'json',
            'expected' => 'json',
            'metadata' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Get the dataset the example belongs to.
     *
     * @return BelongsTo<SpectraDataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SpectraDataset::class, 'dataset_id');
    }
}
