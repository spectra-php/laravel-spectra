<?php

declare(strict_types=1);

namespace Spectra\Actions\Evaluations;

use Spectra\Models\SpectraEvalResult;
use Spectra\Models\SpectraEvalRun;
use Spectra\Models\SpectraRequest;
use stdClass;

/**
 * One run of an evaluation: its result per example with the cost of the
 * example's trace, and, when compared, the result of another run for the
 * same example next to it.
 */
class GetEvalRun
{
    /** Results shown on the screen. */
    private const RESULTS = 500;

    /**
     * Get the run, its results, the runs it can be compared with, and the compared run.
     *
     * @return array{run: array<string, mixed>, results: array<int, array<string, mixed>>, baseline: array<string, mixed>|null, runs: array<int, array<string, mixed>>}
     */
    public function __invoke(SpectraEvalRun $run, ?SpectraEvalRun $baseline = null): array
    {
        $results = collect($this->getResults($run));
        $baselineResults = $baseline !== null ? collect($this->getResults($baseline))->keyBy('example_id') : collect();

        return [
            'run' => GetEvaluations::run($run) + ['total_cost_in_cents' => (float) $results->sum('total_cost_in_cents')],
            'baseline' => $baseline !== null ? GetEvaluations::run($baseline) + ['total_cost_in_cents' => (float) $baselineResults->sum('total_cost_in_cents')] : null,
            'results' => $results->map(fn (array $result) => $result + [
                'baseline' => $result['example_id'] !== null ? $baselineResults->get($result['example_id']) : null,
            ])->values()->all(),
            'runs' => SpectraEvalRun::query()->where('evaluation', $run->evaluation)->whereKeyNot($run->id)
                ->latest('started_at')->limit(20)->get()
                ->map(fn (SpectraEvalRun $other) => GetEvaluations::run($other))->all(),
        ];
    }

    /**
     * Get the run's results with each trace's cost (successful calls only).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getResults(SpectraEvalRun $run): array
    {
        $results = $run->results()->oldest('created_at')->limit(self::RESULTS)->get();

        $costs = SpectraRequest::query()
            ->whereIn('trace_id', $results->pluck('trace_id')->all())
            ->successful()
            ->toBase()
            ->groupBy('trace_id')
            ->selectRaw('trace_id, SUM(total_cost_in_cents) as cost, COUNT(*) as call_count')
            ->get()
            ->keyBy('trace_id');

        return $results->map(fn (SpectraEvalResult $result) => [
            'id' => $result->id,
            'example_id' => $result->example_id,
            'trace_id' => $result->trace_id,
            'input' => $result->input,
            'expected' => $result->expected,
            'output' => $result->output,
            'scores' => $result->scores ?? [],
            'error' => $result->error,
            'latency_ms' => $result->latency_ms,
            'total_cost_in_cents' => $this->readCost($costs->get($result->trace_id)),
            'call_count' => (int) ($costs->get($result->trace_id)->call_count ?? 0),
        ])->all();
    }

    /**
     * Read a trace's summed cost, or 0 when it made no successful call.
     */
    private function readCost(?stdClass $row): float
    {
        return $row !== null && is_numeric($row->cost) ? (float) $row->cost : 0.0;
    }
}
