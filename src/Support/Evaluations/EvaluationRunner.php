<?php

declare(strict_types=1);

namespace Spectra\Support\Evaluations;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionFunction;
use Spectra\Enums\EvalRunStatus;
use Spectra\Enums\ScoreSource;
use Spectra\Evaluations\Evaluation;
use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraDatasetExample;
use Spectra\Models\SpectraEvalResult;
use Spectra\Models\SpectraEvalRun;
use Spectra\Spectra;
use Spectra\Support\Scoring\ScoreRecorder;
use Stringable;
use Throwable;

/**
 * Runs an evaluation over its dataset: each example in a trace of its own
 * (tagged `eval`, inside a span named after the evaluation), its output
 * scored by every scorer, and saved as one result of the run. The scores
 * are also saved on the example's trace, so the trace page shows them.
 *
 * An example whose code throws is saved with the error and no scores; the
 * run carries on. A scorer that throws gives no score for that example.
 */
class EvaluationRunner
{
    /** Characters of an output stored at most. */
    protected const MAX_OUTPUT_LENGTH = 20_000;

    /**
     * Create a new evaluation runner instance.
     */
    public function __construct(
        protected Spectra $spectra,
        protected ScoreRecorder $scores,
    ) {}

    /**
     * Run the evaluation and return the finished run.
     *
     * @param  int|null  $limit  Run only the first N examples
     * @param  (Closure(SpectraEvalResult): void)|null  $progress  Called after each example
     * @param  array<string, mixed>  $metadata  Stored with the run, such as the git commit
     */
    public function run(Evaluation $evaluation, ?int $limit = null, ?Closure $progress = null, array $metadata = []): SpectraEvalRun
    {
        $examples = $this->examples($evaluation, $limit);

        $run = SpectraEvalRun::query()->create([
            'evaluation' => mb_substr($evaluation->name(), 0, 100),
            'dataset' => $evaluation->dataset(),
            'status' => EvalRunStatus::Running,
            'example_count' => $examples->count(),
            'metadata' => $metadata === [] ? null : $metadata,
            'started_at' => Date::now(),
        ]);

        try {
            $results = $examples->map(function (SpectraDatasetExample $example) use ($evaluation, $run, $progress): SpectraEvalResult {
                $result = $this->runExample($evaluation, $run, $example);

                if ($progress !== null) {
                    $progress($result);
                }

                return $result;
            });
        } catch (Throwable $e) {
            $run->forceFill(['status' => EvalRunStatus::Failed, 'finished_at' => Date::now()])->save();

            throw $e;
        } finally {
            $this->spectra->newTrace();
        }

        $run->forceFill([
            'status' => EvalRunStatus::Completed,
            'error_count' => $results->whereNotNull('error')->count(),
            'summary' => $this->summary($results),
            'finished_at' => Date::now(),
        ])->save();

        return $run;
    }

    /**
     * The dataset's examples to run, oldest first.
     *
     * @return Collection<int, SpectraDatasetExample>
     */
    protected function examples(Evaluation $evaluation, ?int $limit): Collection
    {
        $dataset = SpectraDataset::query()->where('name', $evaluation->dataset())->first();

        throw_if($dataset === null, InvalidArgumentException::class, "The dataset [{$evaluation->dataset()}] does not exist. Add examples with Spectra::dataset('{$evaluation->dataset()}')->add(...).");

        $examples = $dataset->examples()->oldest('created_at')->oldest('id')
            ->when($limit !== null, fn ($query) => $query->limit(max(1, (int) $limit)))
            ->get();

        throw_if($examples->isEmpty(), InvalidArgumentException::class, "The dataset [{$evaluation->dataset()}] has no examples.");

        return $examples;
    }

    /**
     * Run one example in a trace of its own, score it and save the result.
     */
    protected function runExample(Evaluation $evaluation, SpectraEvalRun $run, SpectraDatasetExample $example): SpectraEvalResult
    {
        $traceId = (string) Str::uuid();
        $this->spectra->newTrace()->withTraceId($traceId);

        $output = null;
        $error = null;
        $started = hrtime(true);

        try {
            $output = $this->spectra->withTags(['eval'], fn () => $this->spectra->span(
                $evaluation->name(),
                fn () => $evaluation->run($example->input, $example),
                ['eval_run' => $run->id, 'example' => $example->id],
            ));
        } catch (Throwable $e) {
            $error = $e::class.': '.$e->getMessage();
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $scores = $error === null ? $this->score($evaluation, $run, $traceId, $output, $example) : [];

        return SpectraEvalResult::query()->create([
            'run_id' => $run->id,
            'example_id' => $example->id,
            'trace_id' => $traceId,
            'input' => $example->input,
            'expected' => $example->expected,
            'output' => $this->storable($output),
            'scores' => $scores === [] ? null : $scores,
            'error' => $error === null ? null : mb_substr($error, 0, 10_000),
            'latency_ms' => max(0, $latencyMs),
            'created_at' => Date::now(),
        ]);
    }

    /**
     * Each scorer's value for the output; also saved as scores on the trace.
     *
     * @return array<string, bool|int|float|string>
     */
    protected function score(Evaluation $evaluation, SpectraEvalRun $run, string $traceId, mixed $output, SpectraDatasetExample $example): array
    {
        $values = [];

        foreach ($evaluation->scorers() as $name => $scorer) {
            $value = rescue(fn () => $this->callScorer($scorer, [$output, $example->expected, $example->input]));

            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && (! is_string($value) || $value === '')) {
                continue;
            }

            $values[$name] = $value;

            $this->scores->record(
                name: $name,
                value: $value,
                traceId: $traceId,
                source: ScoreSource::Api,
                metadata: ['evaluation' => $evaluation->name(), 'eval_run' => $run->id],
            );
        }

        return $values;
    }

    /**
     * Call a scorer with the output, expected output and input, or only as
     * many of them as it takes: PHP's own functions (`strlen(...)`) throw
     * when given more arguments than they declare.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function callScorer(callable $scorer, array $arguments): mixed
    {
        $function = new ReflectionFunction(Closure::fromCallable($scorer));

        return $scorer(...($function->isVariadic() ? $arguments : array_slice($arguments, 0, $function->getNumberOfParameters())));
    }

    /**
     * Each scorer over the run: the share of yes, the average, or the count
     * per category.
     *
     * @param  Collection<int, SpectraEvalResult>  $results
     * @return array<string, array{type: string, count: int, average: float|null, labels?: array<string, int>}>
     */
    protected function summary(Collection $results): array
    {
        $byScore = [];

        foreach ($results as $result) {
            foreach ($result->scores ?? [] as $name => $value) {
                $byScore[$name][] = $value;
            }
        }

        return collect($byScore)->map(function (array $values): array {
            $first = $values[0];

            if (is_string($first)) {
                return ['type' => 'categorical', 'count' => count($values), 'average' => null, 'labels' => collect($values)->countBy()->sortDesc()->all()];
            }

            $numbers = collect($values)->map(fn (mixed $value) => is_bool($value) ? (float) $value : (is_numeric($value) ? (float) $value : null))->filter(fn (?float $value) => $value !== null);

            return ['type' => is_bool($first) ? 'boolean' : 'numeric', 'count' => count($values), 'average' => $numbers->isEmpty() ? null : round((float) $numbers->avg(), 4)];
        })->all();
    }

    /**
     * An output as it can be stored: arrays and scalars as they are, objects
     * as their array or string form, long strings cut.
     */
    protected function storable(mixed $output): mixed
    {
        $output = match (true) {
            $output instanceof Arrayable => $output->toArray(),
            $output instanceof JsonSerializable => $output->jsonSerialize(),
            $output instanceof Stringable => (string) $output,
            is_object($output) => get_debug_type($output),
            default => $output,
        };

        return is_string($output) && mb_strlen($output) > self::MAX_OUTPUT_LENGTH
            ? mb_substr($output, 0, self::MAX_OUTPUT_LENGTH).'…'
            : $output;
    }
}
