<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;
use Spectra\Data\PlaygroundCall;

/**
 * What the Playground's editor opens: a stored call, or a new prompt when `call`
 * is null, with the body to edit, the models to pick from, where a run goes, what
 * may differ from the original, and why it can't run, if so. `providers` lists
 * the providers a new prompt can go to. `session` is the id a new prompt's runs
 * will share. `run` is the opened call when it was itself sent from the Playground,
 * and `original` the stored call that run started from, if any. `runs` holds the
 * other saved runs that belong with it, newest first, and `run_count` how many
 * there are in all, the opened one included.
 */
readonly class PlaygroundCallResponse extends DataTransferObject
{
    /**
     * Create a new playground call response instance.
     *
     * @param  array<string, mixed>|null  $body
     * @param  list<string>  $models
     * @param  list<string>  $notices
     * @param  list<array{slug: string, name: string, has_key: bool}>  $providers
     * @param  array<int, PlaygroundRunResponse>  $runs
     */
    public function __construct(
        public ?PlaygroundCall $call,
        public string $provider,
        public ?string $endpoint,
        public ?array $body,
        public string $model,
        public array $models,
        public string $base_url,
        public array $notices,
        public ?string $unavailable_reason,
        public array $providers = [],
        public array $runs = [],
        public int $run_count = 0,
        public ?string $session = null,
        public ?PlaygroundRunResponse $run = null,
        public ?PlaygroundCall $original = null,
    ) {}
}
