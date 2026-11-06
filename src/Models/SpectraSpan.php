<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spectra\Enums\SpanKind;
use Spectra\Enums\SpanStatus;
use Spectra\Support\Casts\PreciseDateTime;

/**
 * One step of a trace that is not an AI call: a block of code the app wraps
 * in Spectra::span(), or the request, job or command a trace ran in. AI
 * calls name their span in `parent_span_id`, so a trace is a tree.
 *
 * Spans are never billed and never counted as requests.
 *
 * @property string $id
 * @property string $trace_id
 * @property string $span_id
 * @property string|null $parent_span_id
 * @property string $name
 * @property SpanKind $kind
 * @property string|null $origin
 * @property CarbonInterface $started_at
 * @property CarbonInterface $ended_at
 * @property int $duration_ms
 * @property SpanStatus $status
 * @property string|null $error_type
 * @property string|null $error_message
 * @property array<string, mixed>|null $attributes
 */
class SpectraSpan extends Model
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
            'started_at' => PreciseDateTime::class,
            'ended_at' => PreciseDateTime::class,
            'duration_ms' => 'integer',
            'attributes' => 'json',
            'kind' => SpanKind::class,
            'status' => SpanStatus::class,
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
     * Determine if the span ended with an error.
     */
    public function isFailed(): bool
    {
        return $this->status === SpanStatus::Error;
    }
}
