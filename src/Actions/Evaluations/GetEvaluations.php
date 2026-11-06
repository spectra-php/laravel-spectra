<?php

declare(strict_types=1);

namespace Spectra\Actions\Evaluations;

use Illuminate\Support\Facades\Schema;
use Spectra\Models\SpectraEvalRun;

/**
 * The Evals screen: each evaluation with its latest runs.
 */
class GetEvaluations
{
    /** Runs listed per evaluation. */
    private const RUNS = 10;

    /**
     * Get the evaluations, newest run first.
     *
     * @return array<int, array{name: string, dataset: string, runs: array<int, array<string, mixed>>}>
     */
    public function __invoke(): array
    {
        return SpectraEvalRun::query()->latest('started_at')->limit(500)->get()
            ->groupBy('evaluation')
            ->map(fn ($runs, string $name) => [
                'name' => $name,
                'dataset' => (string) $runs->first()?->dataset,
                'runs' => $runs->take(self::RUNS)->map(fn (SpectraEvalRun $run) => self::run($run))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Format a run as the screens read it.
     *
     * @return array<string, mixed>
     */
    public static function run(SpectraEvalRun $run): array
    {
        return [
            'id' => $run->id,
            'evaluation' => $run->evaluation,
            'dataset' => $run->dataset,
            'status' => $run->status->value,
            'example_count' => $run->example_count,
            'error_count' => $run->error_count,
            'summary' => $run->summary ?? [],
            'metadata' => $run->metadata ?? [],
            'started_at' => $run->started_at?->toIso8601String(),
            'duration_ms' => $run->started_at !== null && $run->finished_at !== null ? (int) round($run->started_at->diffInMilliseconds($run->finished_at)) : null,
        ];
    }

    /**
     * Determine if the evaluation migration has run.
     */
    public function migrated(): bool
    {
        $run = new SpectraEvalRun;

        return rescue(fn () => Schema::connection($run->getConnectionName())->hasTable($run->getTable()), false, report: false);
    }
}
