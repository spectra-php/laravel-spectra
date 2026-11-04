<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;

/**
 * Turns the model-type breakdown into the overview's cost-per-type map, with
 * every known type present (zero when unused) so the chart keeps a stable
 * shape.
 */
class BuildCostByModelType
{
    /**
     * Get the cost per model type, with every known type present.
     *
     * @param  Collection<int, ModelTypeStat>  $modelTypeStats
     * @return array<string, float>
     */
    public function __invoke(Collection $modelTypeStats): array
    {
        $costByModelType = [
            'text' => 0.0, 'embedding' => 0.0, 'image' => 0.0,
            'video' => 0.0, 'tts' => 0.0, 'stt' => 0.0, 'unknown' => 0.0,
        ];

        foreach ($modelTypeStats as $row) {
            $costByModelType[$row->model_type] = $row->cost;
        }

        return $costByModelType;
    }
}
