<?php

declare(strict_types=1);

/**
 * Datasets and evaluations: examples added from code or from a tracked call,
 * an evaluation run over them with spectra:eval (each example in its own
 * trace, scored by every scorer), and the runs on the dashboard.
 */

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spectra\Enums\EvalRunStatus;
use Spectra\Enums\ScoreSource;
use Spectra\Evaluations\Scorers\Contains;
use Spectra\Evaluations\Scorers\ExactMatch;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDataset;
use Spectra\Models\SpectraDatasetExample;
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
        ->and(fn () => Spectra::dataset(Str::repeat('x', 101)))->toThrow(InvalidArgumentException::class);
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

it('ExactMatch ignores case and surrounding spaces', function () {
    expect((new ExactMatch)(' paris ', 'Paris'))->toBeTrue();
});

it('ExactMatch fails on a difference in case when case sensitive', function () {
    expect((new ExactMatch(caseSensitive: true))('paris', 'Paris'))->toBeFalse();
});

it('ExactMatch passes for an array equal to the expected one', function () {
    expect((new ExactMatch)(['a' => 1], ['a' => 1]))->toBeTrue();
});

it('ExactMatch answers null without an expected value', function () {
    expect((new ExactMatch)('Paris', null))->toBeNull();
});

it('Contains passes when every needle is in the output', function () {
    expect((new Contains(['refund', '30 days']))('Refund within 30 days.', null))->toBeTrue();
});

it('Contains looks for the expected value when given no needles', function () {
    expect((new Contains)('It is Paris.', 'paris'))->toBeTrue();
});

it('Contains answers null with no needles and no expected value', function () {
    expect((new Contains)('It is Paris.', null))->toBeNull();
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

it('runs an evaluation from the command by its class', function () {
    seedCapitals();

    $this->artisan('spectra:eval', ['evaluation' => CapitalsEval::class, '--limit' => 2])
        ->expectsOutputToContain('Running capitals-eval over capitals')
        ->expectsOutputToContain('2 examples, 0 with errors')
        ->assertSuccessful();

    expect(SpectraEvalRun::query()->sole()->example_count)->toBe(2);
});

it('fails for an evaluation the command cannot find', function () {
    seedCapitals();

    $this->artisan('spectra:eval', ['evaluation' => 'NoSuchEval'])->assertFailed();

    expect(SpectraEvalRun::query()->exists())->toBeFalse();
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

    it('lists each evaluation with its runs', function () {
        seedCapitals();
        app(EvaluationRunner::class)->run(new CapitalsEval);

        $data = $this->getJson('/spectra/api/evals')->assertOk()->json();

        expect($data['migrated'])->toBeTrue()
            ->and($data['evaluations'][0]['name'])->toBe('capitals-eval')
            ->and($data['evaluations'][0]['runs'][0])->toMatchArray(['dataset' => 'capitals', 'example_count' => 4, 'error_count' => 1, 'status' => 'completed']);
    });

    it('lists the datasets with their number of examples, and whether the user can add to them', function () {
        seedCapitals();
        SpectraDataset::query()->create(['name' => 'empty']);
        Gate::define('annotateSpectra', fn ($user = null) => true);

        $data = $this->getJson('/spectra/api/datasets')->assertOk()->json();

        expect($data)->toMatchArray(['migrated' => true, 'can_curate' => true])
            ->and($data['datasets'])->toHaveCount(2)
            ->and($data['datasets'][0])->toMatchArray(['name' => 'capitals', 'example_count' => 4])
            ->and($data['datasets'][1])->toMatchArray(['name' => 'empty', 'example_count' => 0]);

        Gate::define('annotateSpectra', fn ($user = null) => false);

        $this->getJson('/spectra/api/datasets')->assertOk()->assertJsonPath('can_curate', false);
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

    it('adds a tracked call to a dataset from the dashboard', function () {
        Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Paris.']]]], [
            'endpoint' => '/v1/chat/completions',
            'request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Capital of France?']]],
        ]);

        $added = $this->postJson('/spectra/api/datasets/examples', ['dataset' => 'from-calls', 'request_id' => SpectraRequest::query()->value('id'), 'reply_is_expected' => true])
            ->assertCreated()->json();

        expect($this->getJson("/spectra/api/datasets/{$added['dataset_id']}")->json('examples.0'))
            ->toMatchArray(['input' => ['prompt' => 'Capital of France?'], 'expected' => 'Paris.']);
    });

    it('shows a dataset with its examples', function () {
        $dataset = Spectra::dataset('from-code');
        $dataset->add(['country' => 'France'], expected: 'Paris');

        $data = $this->getJson("/spectra/api/datasets/{$dataset->model()->id}")->assertOk()->json();

        expect($data['dataset'])->toMatchArray(['name' => 'from-code', 'example_count' => 1])
            ->and($data['examples'][0])->toMatchArray(['input' => ['country' => 'France'], 'expected' => 'Paris']);
    });

    it('creates an empty dataset with a description', function () {
        $created = $this->postJson('/spectra/api/datasets', ['name' => 'refunds', 'description' => 'Refund questions'])->assertCreated()->json();

        expect(SpectraDataset::query()->findOrFail($created['id']))
            ->name->toBe('refunds')
            ->description->toBe('Refund questions');

        $this->getJson("/spectra/api/datasets/{$created['id']}")->assertOk()
            ->assertJsonPath('examples', [])
            ->assertJsonPath('latest_input', null)
            ->assertJsonPath('can_curate', true);
    });

    it('refuses a dataset with no name, a name too long or a name already used', function () {
        SpectraDataset::query()->create(['name' => 'refunds']);

        $this->postJson('/spectra/api/datasets', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/spectra/api/datasets', ['name' => Str::repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/spectra/api/datasets', ['name' => 'refunds'])->assertUnprocessable()
            ->assertJsonPath('message', 'A dataset with this name already exists.');

        expect(SpectraDataset::query()->count())->toBe(1);
    });

    it('renames a dataset and moves its runs with it', function () {
        seedCapitals();
        $run = app(EvaluationRunner::class)->run(new CapitalsEval);
        $other = SpectraEvalRun::query()->create(['evaluation' => 'other', 'dataset' => 'other', 'status' => $run->status]);
        $dataset = Spectra::dataset('capitals')->model();

        $this->putJson("/spectra/api/datasets/{$dataset->id}", ['name' => 'world-capitals'])->assertOk();

        $data = $this->getJson("/spectra/api/datasets/{$dataset->id}")->assertOk()->json();

        expect($data['dataset'])->toMatchArray(['name' => 'world-capitals', 'example_count' => 4])
            ->and($data['runs'][0]['id'])->toBe($run->id)
            ->and($run->fresh()->dataset)->toBe('world-capitals')
            ->and($other->fresh()->dataset)->toBe('other');
    });

    it('changes or clears a dataset\'s description', function () {
        $dataset = SpectraDataset::query()->create(['name' => 'world-capitals']);

        $this->putJson("/spectra/api/datasets/{$dataset->id}", ['name' => 'world-capitals', 'description' => 'Every capital'])->assertOk();

        expect($this->getJson("/spectra/api/datasets/{$dataset->id}")->assertOk()->json('dataset'))
            ->toMatchArray(['name' => 'world-capitals', 'description' => 'Every capital']);

        $this->putJson("/spectra/api/datasets/{$dataset->id}", ['name' => 'world-capitals', 'description' => null])->assertOk();

        expect($dataset->fresh())->name->toBe('world-capitals')->description->toBeNull();
    });

    it('refuses a name another dataset uses, or none', function () {
        $dataset = SpectraDataset::query()->create(['name' => 'mine', 'description' => 'Kept']);
        SpectraDataset::query()->create(['name' => 'taken']);

        $this->putJson("/spectra/api/datasets/{$dataset->id}", ['name' => 'taken', 'description' => 'Changed'])->assertUnprocessable()
            ->assertJsonPath('message', 'A dataset with this name already exists.');
        $this->putJson("/spectra/api/datasets/{$dataset->id}", ['name' => '', 'description' => 'Changed'])->assertUnprocessable();

        expect($dataset->fresh())->name->toBe('mine')->description->toBe('Kept');
    });

    it('renames a dataset from code, keeping its examples', function () {
        Spectra::dataset('old')->add('France');

        $renamed = Spectra::dataset('old')->rename('new');

        expect($renamed->examples())->toHaveCount(1);
    });

    it('throws when renamed from code to a name that is used or too long', function () {
        Spectra::dataset('new')->add('France');
        SpectraDataset::query()->create(['name' => 'taken']);

        expect(fn () => Spectra::dataset('new')->rename('taken'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Spectra::dataset('new')->rename(Str::repeat('x', 101)))->toThrow(InvalidArgumentException::class);
    });

    it('accepts a dataset\'s own name when renaming it from code', function () {
        Spectra::dataset('new')->add('France');

        $renamed = Spectra::dataset('new')->rename('new');

        expect($renamed->name)->toBe('new');
    });

    it('deletes a dataset and its examples, and past runs stay', function () {
        seedCapitals();
        $run = app(EvaluationRunner::class)->run(new CapitalsEval);
        $kept = Spectra::dataset('kept')->add('Kept');
        $dataset = Spectra::dataset('capitals')->model();

        $this->deleteJson("/spectra/api/datasets/{$dataset->id}")->assertOk();

        expect(SpectraDataset::query()->pluck('name')->all())->toBe(['kept'])
            ->and(SpectraDatasetExample::query()->pluck('id')->all())->toBe([$kept->id]);

        $this->getJson("/spectra/api/datasets/{$dataset->id}")->assertNotFound();
        $this->getJson("/spectra/api/evals/runs/{$run->id}")->assertOk()->assertJsonCount(4, 'results');
        $this->getJson('/spectra/api/evals')->assertOk()->assertJsonPath('evaluations.0.dataset', 'capitals');
    });

    it('adds an example written on the dashboard, in any JSON shape', function (mixed $input, mixed $expected) {
        $dataset = SpectraDataset::query()->create(['name' => 'written']);

        $this->postJson("/spectra/api/datasets/{$dataset->id}/examples", ['input' => $input, 'expected' => $expected])->assertCreated();

        expect(Spectra::dataset('written')->examples()->sole())
            ->input->toEqual($input)
            ->expected->toEqual($expected)
            ->metadata->toEqual(['source' => 'dashboard'])
            ->source_request_id->toBeNull();
    })->with([
        'plain text, nothing expected' => ['Capital of France?', null],
        'several fields, JSON expected' => [['country' => 'France', 'year' => 2026], ['capital' => 'Paris']],
    ]);

    it('sends the newest example\'s input for the form to start from', function () {
        $dataset = Spectra::dataset('shaped');
        $dataset->add(['country' => 'France']);
        $this->travel(1)->minute();
        $dataset->add(['country' => 'Italy', 'year' => 2026]);

        $latestInput = $this->getJson("/spectra/api/datasets/{$dataset->model()->id}")->assertOk()->json('latest_input');

        expect($latestInput)->toEqual(['country' => 'Italy', 'year' => 2026]);
    });

    it('rejects an example with no input', function () {
        $dataset = SpectraDataset::query()->create(['name' => 'written']);

        $this->postJson("/spectra/api/datasets/{$dataset->id}/examples", ['input' => ''])->assertUnprocessable()->assertJsonValidationErrors('input');

        expect(Spectra::dataset('written')->examples())->toBeEmpty();
    });

    it('answers 404 when the dataset an example is added to does not exist', function () {
        $this->postJson('/spectra/api/datasets/0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f/examples', ['input' => 'Italy'])->assertNotFound();
    });

    it('changes an example, keeping where it came from', function () {
        $dataset = Spectra::dataset('edited');
        $example = $dataset->add(['country' => 'France'], expected: 'Lyon', metadata: ['model' => 'gpt-4o'], sourceRequestId: '0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f');

        $this->putJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}", ['input' => ['country' => 'France', 'year' => 2026], 'expected' => 'Paris'])->assertOk();

        expect($example->fresh())
            ->input->toEqual(['country' => 'France', 'year' => 2026])
            ->expected->toBe('Paris')
            ->metadata->toEqual(['model' => 'gpt-4o'])
            ->source_request_id->toBe('0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f');

        $this->putJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}", ['input' => 'France', 'expected' => null])->assertOk();
        expect($example->fresh()->expected)->toBeNull();

        $this->putJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}", ['input' => ''])->assertUnprocessable();
    });

    it('deletes an example, and past runs keep their results for it', function () {
        seedCapitals();
        $run = app(EvaluationRunner::class)->run(new CapitalsEval);
        $example = Spectra::dataset('capitals')->examples()->first();

        $this->deleteJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}")->assertOk();

        expect(Spectra::dataset('capitals')->examples())->toHaveCount(3);

        $results = $this->getJson("/spectra/api/evals/runs/{$run->id}")->assertOk()->json('results');

        expect($results)->toHaveCount(4)
            ->and($results[0]['input'])->toBe($example->input);
    });

    it('won\'t reach an example through another dataset', function () {
        $example = Spectra::dataset('mine')->add('France');
        $other = SpectraDataset::query()->create(['name' => 'other']);

        $this->putJson("/spectra/api/datasets/{$other->id}/examples/{$example->id}", ['input' => 'Italy'])->assertNotFound();
        $this->deleteJson("/spectra/api/datasets/{$other->id}/examples/{$example->id}")->assertNotFound();

        expect($example->fresh()->input)->toBe('France');
    });

    it('needs the annotateSpectra gate to change datasets and examples', function () {
        $example = Spectra::dataset('guarded')->add('France');
        Gate::define('annotateSpectra', fn ($user = null) => false);

        $this->getJson("/spectra/api/datasets/{$example->dataset_id}")->assertOk()->assertJsonPath('can_curate', false);
        $this->postJson('/spectra/api/datasets', ['name' => 'new'])->assertForbidden();
        $this->putJson("/spectra/api/datasets/{$example->dataset_id}", ['name' => 'new'])->assertForbidden();
        $this->deleteJson("/spectra/api/datasets/{$example->dataset_id}")->assertForbidden();
        $this->postJson("/spectra/api/datasets/{$example->dataset_id}/examples", ['input' => 'Italy'])->assertForbidden();
        $this->putJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}", ['input' => 'Italy'])->assertForbidden();
        $this->deleteJson("/spectra/api/datasets/{$example->dataset_id}/examples/{$example->id}")->assertForbidden();

        expect(SpectraDataset::query()->pluck('name')->all())->toBe(['guarded'])
            ->and(Spectra::dataset('guarded')->examples()->sole()->input)->toBe('France');
    });

    it('marks a dataset as updated when an example is added', function () {
        $dataset = SpectraDataset::query()->create(['name' => 'typed']);
        $this->travel(1)->hour();

        $this->postJson("/spectra/api/datasets/{$dataset->id}/examples", ['input' => 'Italy'])->assertCreated();

        expect($dataset->fresh()->updated_at->gt($dataset->updated_at))->toBeTrue();
    });

    it('needs the annotateSpectra gate to add a call to a dataset', function () {
        Gate::define('annotateSpectra', fn ($user = null) => false);

        $this->postJson('/spectra/api/datasets/examples', ['dataset' => 'x', 'request_id' => '0192f7c3-8a1b-7c2d-9e3f-4a5b6c7d8e9f'])->assertForbidden();
    });
});
