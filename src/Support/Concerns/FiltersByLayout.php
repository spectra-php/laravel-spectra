<?php

namespace Spectra\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Spectra\Models\SpectraRequest;

trait FiltersByLayout
{
    /**
     * @param  Builder<SpectraRequest>  $query
     */
    protected function applyLayoutFilter(Builder $query, string $layout): void
    {
        match ($layout) {
            'text', 'embedding' => $query->whereIn('model_type', ['text', 'embedding']),
            'image' => $query->where('model_type', 'image'),
            'video' => $query->where('model_type', 'video'),
            'audio' => $query->whereIn('model_type', ['tts', 'stt']),
            default => null,
        };
    }
}
