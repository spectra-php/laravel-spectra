<?php

declare(strict_types=1);

namespace Spectra\Evaluations;

use Illuminate\Support\Str;
use Spectra\Models\SpectraDatasetExample;

/**
 * An evaluation: the AI code to test, the dataset to run it over, and how
 * to score each output. Run it with `php artisan spectra:eval`.
 *
 *   class SupportAnswerEval extends Evaluation
 *   {
 *       public function dataset(): string
 *       {
 *           return 'refund-questions';
 *       }
 *
 *       public function run(mixed $input, SpectraDatasetExample $example): mixed
 *       {
 *           return app(SupportAgent::class)->answer($input['question']);
 *       }
 *
 *       public function scorers(): array
 *       {
 *           return [
 *               'mentions_refund' => new Contains('refund'),
 *               'short' => fn (string $output) => Str::length($output) < 400,
 *           ];
 *       }
 *   }
 *
 * Each example runs in a trace of its own, so its AI calls, cost and
 * latency are tracked like any other.
 */
abstract class Evaluation
{
    /**
     * Get the name of the dataset to run over.
     */
    abstract public function dataset(): string;

    /**
     * Run the AI code under test on one example's input, and return its output.
     */
    abstract public function run(mixed $input, SpectraDatasetExample $example): mixed;

    /**
     * Get the scorers, by score name. Each gets the output, the expected output
     * and the input, and returns true or false, a number, a category, or
     * null to give no score.
     *
     * @return array<string, callable(mixed, mixed, mixed): (bool|int|float|string|null)>
     */
    abstract public function scorers(): array;

    /**
     * Get the evaluation's name on the dashboard: the class name in kebab case
     * ("SupportAnswerEval" is "support-answer-eval").
     */
    public function name(): string
    {
        return Str::kebab(class_basename(static::class));
    }
}
