<?php

declare(strict_types=1);

namespace Workbench\App\Evals;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spectra\Evaluations\Evaluation;
use Spectra\Evaluations\Scorers\Contains;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDatasetExample;

/**
 * The demo evaluation: the support agent answering refund questions. The
 * answers are canned (no provider is called), one tracked call per example,
 * and v3 of the prompt answers more of them well than v2, so two runs have
 * something to compare.
 */
class SupportAnswerEval extends Evaluation
{
    /**
     * Create a new support answer eval instance.
     */
    public function __construct(
        public string $promptVersion = 'v3',
    ) {}

    public function dataset(): string
    {
        return 'refund-questions';
    }

    public function run(mixed $input, SpectraDatasetExample $example): mixed
    {
        $good = $this->promptVersion === 'v3' ? mt_rand(1, 100) <= 85 : mt_rand(1, 100) <= 60;
        $answer = $good ? (string) $example->expected : 'Please contact our support team about your order.';

        return Spectra::withPrompt('support-answer', $this->promptVersion, fn () => Spectra::track('openai', 'gpt-5.4-mini', fn () => $this->answer($answer), [
            'endpoint' => '/v1/chat/completions',
            'request_data' => ['model' => 'gpt-5.4-mini', 'messages' => [['role' => 'user', 'content' => $input['question']]]],
        ]))
            ? $answer
            : null;
    }

    /**
     * The canned reply, after a short wait so the call and the run take
     * about as long as a real one. The seeder freezes the clock, so it is
     * moved on too.
     *
     * @return array<string, mixed>
     */
    protected function answer(string $answer): array
    {
        $latency = mt_rand(250, 700);
        usleep($latency * 1_000);

        if (Carbon::hasTestNow()) {
            Carbon::setTestNow(Carbon::now()->addMilliseconds($latency));
        }

        return [
            'id' => 'demo_'.bin2hex(random_bytes(6)),
            'model' => 'gpt-5.4-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => mt_rand(300, 900), 'completion_tokens' => mt_rand(20, 90)],
        ];
    }

    public function scorers(): array
    {
        return [
            'mentions_refund' => new Contains('refund'),
            'answers_question' => fn (string $output, string $expected) => Str::lower($output) === Str::lower($expected),
            'words' => fn (string $output) => Str::wordCount($output),
        ];
    }

    public function name(): string
    {
        return 'support-answer-eval';
    }
}
