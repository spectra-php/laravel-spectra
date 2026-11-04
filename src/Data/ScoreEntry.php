<?php

declare(strict_types=1);

namespace Spectra\Data;

use Spectra\Models\SpectraScore;

/**
 * One score as a call, trace or conversation screen lists it.
 */
readonly class ScoreEntry extends DataTransferObject
{
    /**
     * Create a new score entry instance.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public float|bool|string|null $value,
        public ?string $comment,
        public string $source,
        public string $source_label,
        public ?string $request_id,
        public ?string $trace_id,
        public ?string $conversation_id,
        public ?string $created_at,
    ) {}

    /**
     * Build the entry from a stored score.
     */
    public static function fromModel(SpectraScore $score): self
    {
        return new self(
            id: $score->id,
            name: $score->name,
            type: $score->type->value,
            value: $score->displayValue(),
            comment: $score->comment,
            source: $score->source->value,
            source_label: $score->source->label(),
            request_id: $score->request_id,
            trace_id: $score->trace_id,
            conversation_id: $score->conversation_id,
            created_at: $score->created_at?->toIso8601String(),
        );
    }
}
