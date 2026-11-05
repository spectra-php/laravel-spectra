<?php

declare(strict_types=1);

/**
 * Datasets and evaluations: examples added from code or from a tracked call,
 * an evaluation run over them with spectra:eval (each example in its own
 * trace, scored by every scorer), and the runs on the dashboard.
 */

use Illuminate\Support\Facades\Gate;
use Spectra\Enums\EvalRunStatus;
use Spectra\Enums\ScoreSource;
use Spectra\Evaluations\Scorers\Contains;
use Spectra\Evaluations\Scorers\ExactMatch;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraEvalResult;
use Spectra\Models\SpectraEvalRun;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Evaluations\EvaluationRunner;
use Spectra\Tests\Fixtures\CapitalsEval;

/**
 * The capitals dataset: two the eval gets right, one wrong, one it throws on.
 */
function seedCapitals(): void
{
    $dataset = Spectra::dataset('capitals');
    $dataset->add(['country' => 'France'], expected: 'Paris');
    $dataset->add(['country' => 'Italy'], expected: 'Rome');
    $dataset->add(['country' => 'Spain'], expected: 'Madrid');
    $dataset->add(['country' => 'Atlantis'], expected: 'Poseidonia');
}

it('adds examples to a dataset made on first use', function () {
    seedCapitals();

    expect(SpectraDataset::query()->sole()->name)->toBe('capitals')
        ->and(Spectra::dataset('capitals')->examples()->pluck('expected')->all())->toBe(['Paris', 'Rome', 'Madrid', 'Poseidonia'])
        ->and(fn () => Spectra::dataset(str_repeat('x', 101)))->toThrow(InvalidArgumentException::class);
});

it('adds a tracked call as an example, with its reply as expected when asked', function () {
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Paris.']]]], [
        'endpoint' => '/v1/chat/completions',
        'request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Capital of France?']]],
    ]);
    $call = SpectraRequest::query()->sole();

    $example = Spectra::dataset('from-calls')->addRequest($call, replyIsExpected: true);

    expect($example->input)->toBe(['prompt' => 'Capital of France?'])
        ->and($example->expected)->toBe('Paris.')
        ->and($example->source_request_id)->toBe($call->id);
});

it('scores outputs with the built-in scorers', function () {
    expect((new ExactMatch)(' paris ', 'Paris'))->toBeTrue()
        ->and((new ExactMatch(caseSensitive: true))('paris', 'Paris'))->toBeFalse()
        ->and((new ExactMatch)(['a' => 1], ['a' => 1]))->toBeTrue()
        ->and((new ExactMatch)('Paris', null))->toBeNull()
        ->and((new Contains(['refund', '30 days']))('Refund within 30 days.', null))->toBeTrue()
        ->and((new Contains)('It is Paris.', 'paris'))->toBeTrue()
        ->and((new Contains)('It is Paris.', null))->toBeNull();
});

it('runs each example in its own trace, scores it, and saves the run', function () {
    seedCapitals();

    $run = app(EvaluationRunner::class)->run(new CapitalsEval);
    $results = SpectraEvalResult::query()->oldest('created_at')->get();

    expect($run->status)->toBe(EvalRunStatus::Completed)
        ->and($run->evaluation)->toBe('capitals-eval')
        ->and($run->example_count)->toBe(4)
        ->and($run->error_count)->toBe(1)
        ->and($run->summary)->toEqual([
            'correct' => ['type' => 'boolean', 'count' => 3, 'average' => 0.6667],
            'mentions_expected' => ['type' => 'boolean', 'count' => 3, 'average' => 0.6667],
            'length' => ['type' => 'numeric', 'count' => 3, 'average' => 6.0],
        ])
        ->and($results->pluck('output')->all())->toBe(['Paris', 'Rome', 'Barcelona', null])
        ->and($results[3]->error)->toBe('RuntimeException: No capital for Atlantis')
        ->and($results->pluck('trace_id')->unique())->toHaveCount(4)
        ->and(SpectraRequest::query()->count())->toBe(3)
        ->and(SpectraRequest::query()->pluck('trace_id')->all())->toEqualCanonicalizing($results->take(3)->pluck('trace_id')->all())
        ->and(SpectraSpan::query()->where('name', 'capitals-eval')->count())->toBe(4)
        ->and(SpectraScore::query()->where('trace_id', $results[0]->trace_id)->pluck('name')->sort()->values()->all())->toBe(['correct', 'length', 'mentions_expected'])
        ->and(SpectraScore::query()->first()->source)->toBe(ScoreSource::Api)
        ->and(Spectra::currentTraceId())->not->toBe($results[3]->trace_id);
});

it('runs an evaluation from the command, by class or by name in App\\Evals', function () {
    seedCapitals();

    $this->artisan('spectra:eval', ['evaluation' => CapitalsEval::class, '--limit' => 2])
        ->expectsOutputToContain('Running capitals-eval over capitals')
        ->expectsOutputToContain('2 examples, 0 with errors')
        ->assertSuccessful();

    $this->artisan('spectra:eval', ['evaluation' => 'NoSuchEval'])->assertFailed();

    expect(SpectraEvalRun::query()->sole()->example_count)->toBe(2);
});

it('fails clearly for a missing or empty dataset', function () {
    $this->artisan('spectra:eval', ['evaluation' => CapitalsEval::class])
        ->expectsOutputToContain('The dataset [capitals] does not exist')
        ->assertFailed();

    SpectraDataset::query()->create(['name' => 'capitals']);

    $this->artisan('spectra:eval', ['evaluation' => CapitalsEval::class])
        ->expectsOutputToContain('has no examples')
        ->assertFailed();
});

describe('on the dashboard', function () {
    beforeEach(function () {
        Gate::define('viewSpectra', fn ($user = null) => true);
    });

    it('lists each evaluation with its runs, and the datasets', function () {
        seedCapitals();
        app(EvaluationRunner::class)->run(new CapitalsEval);

        $data = $this->getJson('/spectra/api/evals')->assertOk()->json();

        expect($data['migrated'])->toBeTrue()
            ->and($data['evaluations'][0]['name'])->toBe('capitals-eval')
            ->and($data['evaluations'][0]['runs'][0])->toMatchArray(['dataset' => 'capitals', 'example_count' => 4, 'error_count' => 1, 'status' => 'completed'])
            ->and($data['datasets'][0])->toMatchArray(['name' => 'capitals', 'example_count' => 4]);
    });

    it('shows a run with each result\'s cost, compared with an earlier run', function () {
        seedCapitals();
        $first = app(EvaluationRunner::class)->run(new CapitalsEval);
        $second = app(EvaluationRunner::class)->run(new CapitalsEval);

        $data = $this->getJson("/spectra/api/evals/runs/{$second->id}?compare={$first->id}")->assertOk()->json();

        expect($data['run']['id'])->toBe($second->id)
            ->and($data['baseline']['id'])->toBe($first->id)
            ->and($data['runs'])->toHaveCount(1)
            ->and($data['results'])->toHaveCount(4)
            ->and($data['results'][0])->toMatchArray(['output' => 'Paris', 'call_count' => 1])
            ->and($data['results'][0]['total_cost_in_cents'])->toBeGreaterThan(0)
            ->and($data['results'][0]['baseline']['output'])->toBe('Paris')
            ->and($data['results'][3]['error'])->toContain('No capital for Atlantis');
    });

    it('shows a dataset\'s examples, and adds a call to a dataset', function () {
        Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Paris.']]]], [
            'endpoint' => '/v1/chat/completions',
            'request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Capital of France?']]],
        ]);

        $added = $this->postJson('/spectra/api/datasets/examples', ['dataset' => 'from-calls', 'request_id' => SpectraRequest::query()->value('id'), 'reply_is_expected' => true])
            ->assertCreated()->json();
        $data = $this->getJson("/spectra/api/datasets/{$added['dataset_id']}")->assertOk()->json();

        expect($data['dataset'])->toMatchArray(['name' => 'from-calls', 'example_count' => 1])
            ->and($data['examples'][0])->toMatchArray(['input' => ['prompt' => 'Capital of France?'], 'expected' => 'Paris.']);
    });

    it('needs the annotateSpectra gate to add a call to a dataset', function () {
        Gate::define('annotateSpectra', fn ($user = null) => false);

        $this->postJson('/spectra/api/datasets/examples', ['dataset' => 'x', 'request_id' => '0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f'])->assertForbidden();
    });
});
