<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spectra\Support\Casts\PreciseDateTime;

/**
 * What one run of an evaluation did with one example: the input and the
 * expected output as they were then, the output, each scorer's value, and
 * the trace the AI calls ran in.
 *
 * @property string $id
 * @property string $run_id
 * @property string|null $example_id
 * @property string $trace_id
 * @property mixed $input
 * @property mixed $expected
 * @property mixed $output
 * @property array<string, bool|int|float|string|null>|null $scores
 * @property string|null $error
 * @property int $latency_ms
 * @property CarbonInterface|null $created_at
 */
class SpectraEvalResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'spectra_eval_results';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'input' => 'json',
            'expected' => 'json',
            'output' => 'json',
            'scores' => 'json',
            'latency_ms' => 'integer',
            'created_at' => PreciseDateTime::class,
        ];
    }

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }
}
