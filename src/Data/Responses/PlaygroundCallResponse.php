<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Data\PlaygroundCall;

/**
 * One call opened in the Playground: the call, the body to edit, the models to
 * pick from, where a run goes, what may differ from the original, and why it
 * can't run, if so.
 */
readonly class PlaygroundCallResponse extends DataTransferObject
{
    /**
     * Create a new playground call response instance.
     *
     * @param  array<string, mixed>|null  $body
     * @param  list<string>  $models
     * @param  list<string>  $notices
     */
    public function __construct(
        public PlaygroundCall $call,
        public ?array $body,
        public string $model,
        public array $models,
        public string $base_url,
        public array $notices,
        public ?string $unavailable_reason,
    ) {}
}
