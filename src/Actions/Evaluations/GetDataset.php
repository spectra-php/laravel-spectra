<?php

declare(strict_types=1);

namespace Spectra\Actions\Evaluations;

use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraDatasetExample;
use Spectra\Models\SpectraEvalRun;

/**
 * One dataset: its examples, oldest first, and the evaluations run over it.
 */
class GetDataset
{
    /** Examples shown on the screen. */
    private const EXAMPLES = 500;

    /**
     * Get the dataset, its examples and its latest runs.
     *
     * @return array{dataset: array<string, mixed>, examples: array<int, array<string, mixed>>, runs: array<int, array<string, mixed>>, latest_input: mixed}
     */
    public function __invoke(SpectraDataset $dataset): array
    {
        $examples = $dataset->examples()->oldest('created_at')->limit(self::EXAMPLES)->get();

        return [
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'description' => $dataset->description,
                'example_count' => $dataset->examples()->count(),
            ],
            'examples' => $examples->map(fn (SpectraDatasetExample $example) => [
                'id' => $example->id,
                'input' => $example->input,
                'expected' => $example->expected,
                'metadata' => $example->metadata ?? [],
                'source_request_id' => $example->source_request_id,
                'created_at' => $example->created_at?->toIso8601String(),
            ])->all(),
            'runs' => SpectraEvalRun::query()->where('dataset', $dataset->name)->latest('started_at')->limit(10)->get()
                ->map(fn (SpectraEvalRun $run) => GetEvaluations::run($run))->all(),
            'latest_input' => $dataset->examples()->latest('created_at')->first()?->input,
        ];
    }
}
