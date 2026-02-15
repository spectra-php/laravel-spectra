<?php

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\ModelTypeStat;

class BuildCostByModelType
{
    /**
     * @param  \Illuminate\Support\Collection<int, ModelTypeStat>  $modelTypeStats
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
