<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spectra\Enums\ScoreSource;
use Spectra\Enums\ScoreType;

/**
 * A quality signal attached to an AI call, a trace or a conversation: a user's
 * thumbs up, a rating, a model's judgement or a reviewer's label. Recorded
 * with Spectra::score().
 *
 * @property string $id
 * @property string $name
 * @property ScoreType $type
 * @property float|null $value
 * @property string|null $label
 * @property string|null $comment
 * @property ScoreSource $source
 * @property string|null $request_id
 * @property string|null $trace_id
 * @property string|null $conversation_id
 * @property string|null $author_type
 * @property string|null $author_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 */
class SpectraScore extends Model
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
            'type' => ScoreType::class,
            'source' => ScoreSource::class,
            'value' => 'float',
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
     * Get the AI call the score was given to.
     *
     * @return BelongsTo<SpectraRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SpectraRequest::class, 'request_id');
    }

    /**
     * Get the score as it reads: a number, true or false, or the category.
     */
    public function displayValue(): float|bool|string|null
    {
        return match ($this->type) {
            ScoreType::Boolean => $this->value === 1.0,
            ScoreType::Categorical => $this->label,
            ScoreType::Numeric => $this->value,
        };
    }
}
