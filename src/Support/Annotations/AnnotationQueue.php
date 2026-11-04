<?php

declare(strict_types=1);

namespace Spectra\Support\Annotations;

use InvalidArgumentException;
use Spectra\Support\Tracking\RequestContext;

/**
 * One queue from `spectra.annotations.queues`: the scores its reviewers
 * give, and which traces it samples as they are recorded.
 */
readonly class AnnotationQueue
{
    /** Filter keys a queue can sample by. */
    public const FILTERS = ['provider', 'model', 'prompt_name', 'prompt_version', 'tag', 'origin', 'failed'];

    /**
     * Create a new annotation queue instance.
     *
     * @param  array<int, array{name: string, type: string, min: float|null, max: float|null, options: array<int, string>}>  $scores
     * @param  array<string, mixed>  $filter
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $scores,
        public float $sample = 0.0,
        public array $filter = [],
    ) {}

    /**
     * Build a queue from its config entry, failing loudly on a typo.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        throw_if(mb_strlen($name) > 100, InvalidArgumentException::class, "The Spectra annotation queue [{$name}] needs a name of at most 100 characters.");

        $scores = collect((array) ($config['scores'] ?? []))->map(function (mixed $definition, int|string $score) use ($name): array {
            $definition = is_string($definition) ? ['type' => $definition] : (array) $definition;
            $type = (string) ($definition['type'] ?? '');

            throw_unless(is_string($score) && $score !== '', InvalidArgumentException::class, "The scores of the Spectra annotation queue [{$name}] need names as keys.");
            throw_unless(in_array($type, ['boolean', 'numeric', 'categorical'], true), InvalidArgumentException::class, "The score [{$score}] of the Spectra annotation queue [{$name}] needs a type: boolean, numeric or categorical.");
            throw_if($type === 'categorical' && blank($definition['options'] ?? null), InvalidArgumentException::class, "The categorical score [{$score}] of the Spectra annotation queue [{$name}] needs options.");

            return [
                'name' => $score,
                'type' => $type,
                'min' => isset($definition['min']) && is_numeric($definition['min']) ? (float) $definition['min'] : null,
                'max' => isset($definition['max']) && is_numeric($definition['max']) ? (float) $definition['max'] : null,
                'options' => collect((array) ($definition['options'] ?? []))->map(fn (mixed $option) => (string) $option)->values()->all(),
            ];
        })->values()->all();

        throw_if($scores === [], InvalidArgumentException::class, "The Spectra annotation queue [{$name}] needs at least one score.");

        $filter = (array) ($config['filter'] ?? []);
        $unknown = collect($filter)->keys()->diff(self::FILTERS);
        throw_if($unknown->isNotEmpty(), InvalidArgumentException::class, "The Spectra annotation queue [{$name}] has an unknown filter [{$unknown->implode(', ')}]. Use ".implode(', ', self::FILTERS).'.');

        return new self(
            name: $name,
            description: isset($config['description']) ? (string) $config['description'] : null,
            scores: $scores,
            sample: min(1.0, max(0.0, (float) ($config['sample'] ?? 0))),
            filter: $filter,
        );
    }

    /**
     * Determine if the queue samples this call's trace: the call matches the
     * filter, and the trace falls in the sampled share (picked by trace id,
     * so every call of a trace gets the same answer).
     */
    public function samples(RequestContext $context, bool $failed): bool
    {
        if ($this->sample <= 0.0 || ! $this->matches($context, $failed)) {
            return false;
        }

        return $this->sample >= 1.0 || (crc32($this->name.'|'.$context->traceId) % 10_000) < $this->sample * 10_000;
    }

    /**
     * Check a reviewer's values against the queue's scores, and return them
     * as Spectra::score() takes them. Empty values are left out.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, bool|float|string>
     */
    public function validate(array $values): array
    {
        $scores = [];

        foreach ($this->scores as $score) {
            $value = $values[$score['name']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $scores[$score['name']] = match ($score['type']) {
                'boolean' => $this->boolean($score['name'], $value),
                'numeric' => $this->number($score, $value),
                default => $this->option($score, $value),
            };
        }

        return $scores;
    }

    /**
     * Determine if the call matches every filter of the queue.
     */
    protected function matches(RequestContext $context, bool $failed): bool
    {
        foreach ($this->filter as $key => $expected) {
            $matches = match ($key) {
                'provider' => in_array($context->provider, (array) $expected, true),
                'model' => in_array($context->model, (array) $expected, true) || in_array($context->snapshot, (array) $expected, true),
                'prompt_name' => in_array($context->promptName, (array) $expected, true),
                'prompt_version' => in_array($context->promptVersion, (array) $expected, true),
                'tag' => array_intersect($context->tags, (array) $expected) !== [],
                'origin' => in_array($context->origin, (array) $expected, true),
                'failed' => $failed === (bool) $expected,
                default => false,
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * A yes/no value.
     */
    protected function boolean(string $name, mixed $value): bool
    {
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        throw_if($boolean === null, InvalidArgumentException::class, "The score [{$name}] takes yes or no.");

        return $boolean;
    }

    /**
     * A number within the score's range.
     *
     * @param  array{name: string, type: string, min: float|null, max: float|null, options: array<int, string>}  $score
     */
    protected function number(array $score, mixed $value): float
    {
        throw_unless(is_numeric($value), InvalidArgumentException::class, "The score [{$score['name']}] takes a number.");

        $number = (float) $value;
        throw_if($score['min'] !== null && $number < $score['min'], InvalidArgumentException::class, "The score [{$score['name']}] is at least {$score['min']}.");
        throw_if($score['max'] !== null && $number > $score['max'], InvalidArgumentException::class, "The score [{$score['name']}] is at most {$score['max']}.");

        return $number;
    }

    /**
     * One of the score's options.
     *
     * @param  array{name: string, type: string, min: float|null, max: float|null, options: array<int, string>}  $score
     */
    protected function option(array $score, mixed $value): string
    {
        throw_unless(is_string($value) && in_array($value, $score['options'], true), InvalidArgumentException::class, "The score [{$score['name']}] takes one of: ".implode(', ', $score['options']).'.');

        return $value;
    }

    /**
     * The queue as the dashboard reads it.
     *
     * @return array{name: string, description: string|null, scores: array<int, array<string, mixed>>, sample: float, filter: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'scores' => $this->scores, 'sample' => $this->sample, 'filter' => $this->filter];
    }
}
