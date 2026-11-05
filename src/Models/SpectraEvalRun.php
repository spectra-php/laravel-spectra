<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spectra\Enums\EvalRunStatus;
use Spectra\Support\Casts\PreciseDateTime;

/**
 * One run of an evaluation over its dataset: when it ran, how many examples
 * it covered and failed on, and each scorer's result over all of them.
 *
 * @property string $id
 * @property string $evaluation
 * @property string $dataset
 * @property EvalRunStatus $status
 * @property int $example_count
 * @property int $error_count
 * @property array<string, array{type: string, count: int, average: float|null, labels?: array<string, int>}>|null $summary
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 */
class SpectraEvalRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'status' => EvalRunStatus::class,
            'example_count' => 'integer',
            'error_count' => 'integer',
            'summary' => 'json',
            'metadata' => 'json',
            'started_at' => PreciseDateTime::class,
            'finished_at' => PreciseDateTime::class,
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
     * Get the run's result per example.
     *
     * @return HasMany<SpectraEvalResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(SpectraEvalResult::class, 'run_id');
    }
}
