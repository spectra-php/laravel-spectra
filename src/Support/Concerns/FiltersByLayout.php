<?php

declare(strict_types=1);

namespace Spectra\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Spectra\Models\SpectraRequest;

/**
 * Narrows a dashboard query to the model types of the configured layout
 * (`spectra.dashboard.layout`), so an audio-only or image-only app sees only
 * those requests. An unknown layout logs a warning and filters nothing rather
 * than hiding every row.
 */
trait FiltersByLayout
{
    /**
     * Limit the query to the model types the dashboard layout shows.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    protected function applyLayoutFilter(Builder $query, string $layout): void
    {
        switch ($layout) {
            case 'text':
            case 'embedding':
                $query->whereIn('model_type', ['text', 'embedding']);
                break;
            case 'image':
                $query->where('model_type', 'image');
                break;
            case 'video':
                $query->where('model_type', 'video');
                break;
            case 'audio':
                $query->whereIn('model_type', ['tts', 'stt']);
                break;
            case 'full':
                break;
            default:
                Log::warning(
                    "Spectra: unknown dashboard layout \"{$layout}\" — no model-type filter applied. "
                    .'Valid values: full, text, embedding, image, video, audio.'
                );
        }
    }
}
