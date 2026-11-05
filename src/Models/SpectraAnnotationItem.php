<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spectra\Enums\AnnotationStatus;

/**
 * A trace waiting in a review queue, or reviewed there. The reviewer's
 * labels are saved as scores on the trace; the item keeps who reviewed it,
 * when, and their comment.
 *
 * @property string $id
 * @property string $queue
 * @property string $trace_id
 * @property string|null $request_id
 * @property AnnotationStatus $status
 * @property string|null $comment
 * @property string|null $reviewer_type
 * @property string|null $reviewer_id
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $created_at
 */
class SpectraAnnotationItem extends Model
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
            'status' => AnnotationStatus::class,
            'reviewed_at' => 'datetime',
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
}
