# Evaluations

When you change a prompt, a model or the code around them, you want to know before you ship whether the answers got better. An evaluation runs your AI code over a dataset of examples, scores every output, and saves the run, so you can compare it with the last one example by example.

Each example runs in a trace of its own, so its AI calls are tracked like any other: their cost, tokens and latency show on the run, and the trace page shows exactly what was sent.

<a name="datasets"></a>
## Datasets

A dataset is a named set of examples: the input your code gets, and the output you expect for it. Add examples with `Spectra::dataset()`. A dataset is made the first time something is added to it:

```php
use Spectra\Facades\Spectra;

$dataset = Spectra::dataset('refund-questions');

$dataset->add(['question' => 'Can I get a refund on my annual plan?'], expected: 'Yes, within 30 days.');
$dataset->add(['question' => 'How long does a refund take?'], expected: 'Five to ten business days.');
```

The input and the expected output can be any value that encodes to JSON: a string, or an array with the fields your code needs. The expected output is optional; leave it out when your scorers don't compare against one. `add()` also takes `metadata`.

**From real traffic.** The best examples are often real questions. A call's page in the dashboard has an **Add to dataset** card: it saves the call's last user message as the input (`['prompt' => ...]`), and its reply as the expected output when you tick the box.

<img class="screenshot" src="/images/dashboard/request-add-to-dataset.png" alt="The Add to dataset card on a call" />

In code:

```php
Spectra::dataset('refund-questions')->addRequest($requestId, replyIsExpected: true);
```

Adding from the dashboard needs the `annotateSpectra` gate (see [Who Can Review](/annotations#who-can-review)).

<a name="writing-an-evaluation"></a>
## Writing an Evaluation

An evaluation is a class that extends `Spectra\Evaluations\Evaluation`. Put it in `app/Evals`:

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
- `run()` calls the code under test with one example's input and returns its output. Call it the way your app does; Spectra tracks the AI calls it makes.
- `scorers()` names each score and how to compute it. A scorer gets the output, the expected output and the input, and returns `true` or `false`, a number, a category, or `null` to give no score for that example.

The evaluation's name on the dashboard is its class name in kebab case (`support-answer-eval`); override `name()` to change it. The class is resolved from the container, so its constructor can take your services.

<a name="built-in-scorers"></a>
### Built-in Scorers

| Scorer | Scores |
| --- | --- |
| `new ExactMatch` | Whether the output equals the expected output. Strings are compared trimmed and case-insensitively (`new ExactMatch(caseSensitive: true)` to keep case); anything else as JSON. No score without an expected output. |
| `new Contains('refund')` | Whether the output contains every given string (`new Contains(['refund', '30 days'])`), or, with none given, the expected output. Case-insensitive unless `caseSensitive: true`. |

To have a model judge the answer, write the scorer as a closure that calls the model and returns its rating. The judge's own calls are tracked in the example's trace too.

<a name="running-an-evaluation"></a>
## Running an Evaluation

Run it with `spectra:eval`, by class name or by its name in `App\Evals`:

```shell
php artisan spectra:eval SupportAnswerEval
php artisan spectra:eval SupportAnswerEval --limit=10
```

The command runs every example of the dataset (or the first `--limit`), in order, and prints each scorer's result:

```
 Score             Result     Examples
 mentions_refund   87.5% yes  8
 exact             62.5% yes  8
 short             100% yes   8

8 examples, 0 with errors. Run 0196f3c2-….
```

Each example runs in a fresh trace, inside a span named after the evaluation and tagged `eval`, so the evaluation's calls are easy to tell from real traffic (filter the requests list by the `eval` tag). An example whose code throws is saved with the error and gets no scores; the run carries on. A scorer that throws gives no score for that example, and the exception goes to your exception handler.

Every score is also saved as a [score](/scores) on the example's trace, so the trace page shows it.

To run an evaluation from code, such as a test or a scheduled job, use the runner:

```php
use Spectra\Support\Evaluations\EvaluationRunner;

$run = app(EvaluationRunner::class)->run(new SupportAnswerEval, metadata: ['commit' => $sha]);

$run->summary; // ['exact' => ['type' => 'boolean', 'count' => 8, 'average' => 0.625], ...]
```

<a name="comparing-runs"></a>
## Comparing Runs

The dashboard's **Evals** screen lists each evaluation with its latest runs, every scorer's result per run, and the datasets.

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
