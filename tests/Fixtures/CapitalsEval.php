<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

use RuntimeException;
use Spectra\Evaluations\Evaluation;
use Spectra\Evaluations\Scorers\Contains;
use Spectra\Evaluations\Scorers\ExactMatch;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDatasetExample;

/**
 * An evaluation for the tests: "answers" a capital question with one
 * tracked call, and throws for an input it can't answer.
 */
class CapitalsEval extends Evaluation
{
    /** @var array<string, string> */
    public const ANSWERS = ['France' => 'Paris', 'Italy' => 'Rome', 'Spain' => 'Barcelona'];

    public function dataset(): string
    {
        return 'capitals';
    }

    public function run(mixed $input, SpectraDatasetExample $example): mixed
    {
        $answer = self::ANSWERS[$input['country']] ?? throw new RuntimeException("No capital for {$input['country']}");

        return Spectra::track('openai', 'gpt-4o', fn () => [
            'choices' => [['message' => ['content' => $answer]]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 2],
        ]) ? $answer : null;
    }

    public function scorers(): array
    {
        return [
            'correct' => new ExactMatch,
            'mentions_expected' => new Contains,
            'length' => strlen(...),
            'broken' => fn () => throw new RuntimeException('Scorer bug'),
        ];
    }
}
