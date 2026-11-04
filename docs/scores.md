# Scores

Scores tell you how good your AI answers are, not just what they cost. A score is a quality signal attached to an AI call, a [trace](/usage#traces) or a [conversation](/usage#conversation-tracking): a user's thumbs up, a 1-5 rating, a model's judgement of an answer, or a reviewer's label.

The dashboard shows each call's, trace's and conversation's scores, and the Overview sums every score name for the period: the share of yes, the average, or the most common labels.

<a name="recording-a-score"></a>
## Recording a Score

Record a score with `Spectra::score()`. The type of the value sets the type of the score:

```php
use Spectra\Enums\ScoreSource;
use Spectra\Facades\Spectra;

// Yes or no: a thumbs up or down.
Spectra::score('helpful', true, traceId: $traceId, source: ScoreSource::User);

// A number: a rating, an accuracy.
Spectra::score('answer_quality', 4.5, request: $requestId, source: ScoreSource::Model);

// A category.
Spectra::score('sentiment', 'positive', conversationId: $chat->id);
```

| Argument | |
| --- | --- |
| `name` | What the score measures, up to 100 characters. Use the same name for the same measure, so the Overview can sum it. |
| `value` | `true` / `false`, a number, or a category name. |
| `request` | The AI call: its id, or the `SpectraRequest`. A model also gives the score the call's trace and conversation. |
| `traceId` | The trace. |
| `conversationId` | The conversation. |
| `comment` | Free text, such as what the user wrote with their thumbs down. |
| `source` | Who gave it: `ScoreSource::User` (an end user), `Model` (a model judging the answer), `Annotation` (a reviewer; see [Annotation Queues](/annotations)) or `Api` (your code, the default). |
| `author` | The model (for example the user) who gave the score. |
| `metadata` | Any other key-value context, such as the judge model's name. |

Without `request`, `traceId` or `conversationId`, the score goes to the trace of the current request, job or command.

`Spectra::score()` returns the saved `SpectraScore`, or `null` when it could not be saved: Spectra is disabled, the [scores migration](/migrations#scores-migration) hasn't run yet, or the database failed (the error goes to your exception handler). A missing name, a missing target or a value it can't store throws an `InvalidArgumentException`, because that is a bug in the calling code.

<a name="user-feedback"></a>
## User Feedback

Feedback usually arrives in a later HTTP request than the answer it rates: the user reads the answer, then clicks a thumb. Send the answer's trace id along with the answer, and pass it back with the feedback. Without it, the score would go to the feedback request's own trace.

```php
use Spectra\Facades\Spectra;

// When answering
public function ask(Request $request)
{
    $answer = $this->agent->answer($request->input('question'));

    return response()->json([
        'answer' => $answer,
        'trace_id' => Spectra::currentTraceId(),
    ]);
}

// When the user rates the answer
public function feedback(Request $request)
{
    Spectra::score(
        'helpful',
        $request->boolean('helpful'),
        traceId: $request->input('trace_id'),
        comment: $request->input('comment'),
        source: ScoreSource::User,
        author: $request->user(),
    );

    return response()->noContent();
}
```

<a name="scoring-one-call"></a>
## Scoring One Call

To score one AI call instead of the whole trace, use its id. Inside `Spectra::track()`, the context's id is the id the call is saved under:

```php
$answer = Spectra::track('openai', 'gpt-5.4', function ($context) use ($question) {
    $response = OpenAI::chat()->create([...]);

    // For example, have a cheaper model judge the answer later.
    JudgeAnswer::dispatch($context->id, $question, $response->choices[0]->message->content);

    return $response;
});
```

```php
// In JudgeAnswer::handle()
Spectra::score('answer_quality', $rating, request: $this->requestId, source: ScoreSource::Model, metadata: [
    'judge' => 'gpt-5.4-mini',
]);
```

A call tracked on its own (through the HTTP client or the OpenAI SDK) reaches your code in the [`RequestTracked`](/opentelemetry#events) event, whose `$request['id']` is its id.

<a name="reacting-to-scores"></a>
## Reacting to Scores

Every saved score dispatches `Spectra\Events\ScoreRecorded` with the `SpectraScore`:

```php
use Spectra\Events\ScoreRecorded;

Event::listen(function (ScoreRecorded $event) {
    if ($event->score->name === 'helpful' && $event->score->displayValue() === false) {
        // Flag the conversation for a support agent.
    }
});
```

<a name="querying-scores"></a>
## Querying Scores

Scores are stored in the `spectra_scores` table, through the `Spectra\Models\SpectraScore` model:

```php
use Spectra\Models\SpectraScore;

$unhelpful = SpectraScore::query()
    ->where('name', 'helpful')
    ->where('value', 0)
    ->where('created_at', '>=', now()->subWeek())
    ->count();
```

`value` holds numbers, and `1` / `0` for yes/no scores. `label` holds categories. `displayValue()` returns the score as it reads: a number, `true` or `false`, or the category.

`spectra:prune` deletes scores older than its cutoff, together with the requests. See [`spectra:prune`](/commands#spectra-prune).

<a name="testing"></a>
## Testing

With `Spectra::fake()`, scores are checked the same way but kept in memory. See [Testing](/testing#scores).
