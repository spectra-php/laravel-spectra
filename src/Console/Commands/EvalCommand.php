<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Spectra\Evaluations\Evaluation;
use Spectra\Models\SpectraEvalResult;
use Spectra\Support\Evaluations\EvaluationRunner;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs an evaluation class over its dataset and prints each scorer's result.
 * The run, with every example's output and scores, shows on the dashboard's
 * Evals screen.
 */
#[AsCommand(name: 'spectra:eval')]
class EvalCommand extends Command
{
    protected $signature = 'spectra:eval
                            {evaluation : The evaluation class, or its name in App\\Evals}
                            {--limit= : Run only the first N examples}';

    protected $description = 'Run an evaluation over its dataset';

    /**
     * Run the evaluation and print its summary.
     */
    public function handle(EvaluationRunner $runner): int
    {
        $class = $this->resolveClass($this->argument('evaluation'));

        if ($class === null) {
            $this->components->error("No evaluation [{$this->argument('evaluation')}]. Pass a class that extends ".Evaluation::class.', or its name in App\\Evals.');

            return self::FAILURE;
        }

        /** @var Evaluation $evaluation */
        $evaluation = $this->laravel->make($class);
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->components->info("Running {$evaluation->name()} over {$evaluation->dataset()}.");
        $bar = $this->output->createProgressBar();

        try {
            $run = $runner->run($evaluation, $limit, fn (SpectraEvalResult $result) => $bar->advance());
        } catch (InvalidArgumentException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Score', 'Result', 'Examples'], collect($run->summary ?? [])->map(fn (array $score, string $name) => [
            $name,
            match ($score['type']) {
                'boolean' => round(($score['average'] ?? 0) * 100, 1).'% yes',
                'numeric' => (string) $score['average'],
                default => collect($score['labels'] ?? [])->map(fn (int $count, string $label) => "{$label} {$count}")->implode(', '),
            },
            $score['count'],
        ])->values()->all());

        $this->line("{$run->example_count} examples, {$run->error_count} with errors. Run {$run->id}.");

        return self::SUCCESS;
    }

    /**
     * Resolve the evaluation class from a class name, or a name in App\Evals.
     *
     * @return class-string<Evaluation>|null
     */
    protected function resolveClass(string $name): ?string
    {
        foreach ([$name, "App\\Evals\\{$name}"] as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Evaluation::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
