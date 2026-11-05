# Evaluations

Say your app has a support bot and you rewrite its prompt to make the answers shorter. Did they stay correct? Reading ten answers by hand tells you something. Reading them again after every change to the prompt, the model or the code around them does not scale. An evaluation does that reading for you, before you ship.

<a name="what-is-an-evaluation"></a>
## What Is an Evaluation?

An evaluation is a test for your AI code. It takes a fixed list of questions you have picked, asks your code every one of them, and grades each answer. Four words come up on this page and on the dashboard:

- A **dataset** is the list of questions. Each entry is an **example**: the input your code gets and, when you know it, the answer you expect.
- A **scorer** grades one answer. It can be a simple check (does the answer mention a refund?), a comparison with the expected answer, or a call to another model that rates the answer.
- A **run** is one pass over the whole dataset: every example's answer with its scores. Spectra saves each run, so you can put the run after your change next to the run before it and see what moved, example by example.

A model doesn't always give the same answer twice, so you read an evaluation as a whole: the share of examples that passed, and the examples whose answer or scores changed, rather than any one answer.

Each example runs in a [trace](/usage#traces) of its own, so its AI calls are tracked like any other: their cost, tokens and latency show on the run, and the trace page shows exactly what was sent.

<a name="datasets"></a>
## Datasets

A dataset is a named list of examples. Each example holds the input your code gets and, optionally, the output you expect for it. Add examples with `Spectra::dataset()`. The dataset is created the first time you add to it, so there is nothing to set up:

```php
use Spectra\Facades\Spectra;

$dataset = Spectra::dataset('refund-questions');

$dataset->add(['question' => 'Can I get a refund on my annual plan?'], expected: 'Yes, within 30 days.');
$dataset->add(['question' => 'How long does a refund take?'], expected: 'Five to ten business days.');
```

The input and the expected output can be any value that encodes to JSON: a string, or an array with the fields your code needs. Leave the expected output out when your scorers don't compare against one, for example when a model judges the answer. `add()` also takes `metadata`.

**From real traffic.** The best examples are often real questions your users asked. A call's page in the dashboard has an **Add to dataset** card: it saves the call's last user message as the input (`['prompt' => ...]`), and its reply as the expected output when you tick the box. Tick it when the reply was a good one, so it becomes the answer you want to keep getting.

<img class="screenshot" src="/images/dashboard/request-add-to-dataset.png" alt="The Add to dataset card on a call" />

In code:

```php
Spectra::dataset('refund-questions')->addRequest($requestId, replyIsExpected: true);
```

Adding from the dashboard needs the `annotateSpectra` gate (see [Who Can Review](/annotations#who-can-review)).

<a name="writing-an-evaluation"></a>
## Writing an Evaluation

An evaluation is a class that extends `Spectra\Evaluations\Evaluation` and answers three questions: which dataset to run over, how to get an answer for one example, and how to grade it. Put it in `app/Evals`:

```php
namespace App\Evals;

use App\Support\SupportAgent;
use Illuminate\Support\Str;
use Spectra\Evaluations\Evaluation;
use Spectra\Evaluations\Scorers\Contains;
use Spectra\Evaluations\Scorers\ExactMatch;
use Spectra\Models\SpectraDatasetExample;

class SupportAnswerEval extends Evaluation
{
    public function dataset(): string
    {
        return 'refund-questions';
    }

    public function run(mixed $input, SpectraDatasetExample $example): mixed
    {
        return app(SupportAgent::class)->answer($input['question']);
    }

    public function scorers(): array
    {
        return [
            'mentions_refund' => new Contains('refund'),
            'exact' => new ExactMatch,
            'short' => fn (string $output) => Str::length($output) < 400,
        ];
    }
}
```

- `dataset()` names the dataset to run over.
- `run()` gets one example's input and returns your code's answer. Call your code the way your app does, so the evaluation tests what users get. Spectra tracks the AI calls it makes.
- `scorers()` lists the grades, each with a name and a way to compute it. A scorer gets the output, the expected output and the input, and returns `true` or `false`, a number, a category, or `null` to give no score for that example.

The evaluation's name on the dashboard is its class name in kebab case (`support-answer-eval`); override `name()` to change it. The class is resolved from the container, so its constructor can take your services.

<a name="built-in-scorers"></a>
### Built-in Scorers

Two scorers ship with Spectra. Any other check is a closure, like `short` above.

| Scorer | Scores |
| --- | --- |
| `new ExactMatch` | Whether the output equals the expected output. Strings are compared trimmed and case-insensitively (`new ExactMatch(caseSensitive: true)` to keep case); anything else as JSON. No score without an expected output. |
| `new Contains('refund')` | Whether the output contains every given string (`new Contains(['refund', '30 days'])`), or, with none given, the expected output. Case-insensitive unless `caseSensitive: true`. |

**A model as the judge.** Some answers have no single right wording, so no string check can grade them. Have a model do it: write the scorer as a closure that sends the question, the expected answer and the output to a model and returns its rating. The judge's own calls are tracked in the example's trace too, so you can see what the judging costs.

<a name="running-an-evaluation"></a>
## Running an Evaluation

Run it with `spectra:eval`, by class name or by its name in `App\Evals`:

```shell
php artisan spectra:eval SupportAnswerEval
php artisan spectra:eval SupportAnswerEval --limit=10
```

The command runs every example of the dataset (or the first `--limit`), in order, and prints one row per scorer:

```
 Score             Result     Examples
 mentions_refund   87.5% yes  8
 exact             62.5% yes  8
 short             100% yes   8

8 examples, 0 with errors. Run 0196f3c2-….
```

`87.5% yes` means seven of the eight answers passed `mentions_refund`. A numeric scorer shows its average, and a categorical one how often each label was given.

Each example runs in a fresh trace, inside a span named after the evaluation and tagged `eval`, so the evaluation's calls are easy to tell from real traffic: filter the requests list by the `eval` tag. An example whose code throws is saved with the error and gets no scores; the run carries on. A scorer that throws gives no score for that example, and the exception goes to your exception handler.

Every score is also saved as a [score](/scores) on the example's trace, so the trace page shows it.

To run an evaluation from code, such as a test or a scheduled job, use the runner:

```php
use Spectra\Support\Evaluations\EvaluationRunner;

$run = app(EvaluationRunner::class)->run(new SupportAnswerEval, metadata: ['commit' => $sha]);

$run->summary; // ['exact' => ['type' => 'boolean', 'count' => 8, 'average' => 0.625], ...]
```

<a name="comparing-runs"></a>
## Comparing Runs

A run on its own tells you how your code does today. Two runs tell you whether a change helped. The dashboard's **Evals** screen lists each evaluation with its latest runs, every scorer's result per run, and the datasets.

<img class="screenshot" src="/images/dashboard/evals.png" alt="An evaluation with two runs, and a dataset" />

Open a run to see each example's input, expected output, output, scores, latency and cost, with a link to its trace.

<img class="screenshot" src="/images/dashboard/eval-run.png" alt="An evaluation run, example by example" />

Pick an earlier run under **Compare with** to see:

- each scorer's change, in points for yes/no scores;
- the earlier run's output under each example's;
- the scores that changed, outlined, with the earlier value on hover;
- **Changed only**, to hide the examples whose output and scores stayed the same.

<img class="screenshot" src="/images/dashboard/eval-compare.png" alt="A v3 run compared with the v2 run" />

A dataset's page lists its examples and the runs over it.

<img class="screenshot" src="/images/dashboard/eval-dataset.png" alt="A dataset with its examples and the runs over it" />

<a name="storage"></a>
## Storage

Datasets, examples, runs and results are stored in four tables, added by the [evaluation migration](/migrations#evaluation-migration). A result keeps the input and expected output as they were when it ran, so editing a dataset doesn't change past runs. Outputs longer than 20,000 characters are cut.

`spectra:prune` doesn't delete datasets or runs: they are yours to keep. The traces of old runs are pruned like any other.
