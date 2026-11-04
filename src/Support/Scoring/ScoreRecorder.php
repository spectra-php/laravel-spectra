<?php

declare(strict_types=1);

namespace Spectra\Support\Scoring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spectra\Enums\ScoreSource;
use Spectra\Enums\ScoreType;
use Spectra\Events\ScoreRecorded;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Throwable;

/**
 * Saves the scores Spectra::score() records: user feedback, model
 * judgements and reviewer labels on a call, a trace or a conversation.
 *
 * A wrong argument is the caller's bug and throws at once. Saving never
 * does: until the app runs the migration that adds the scores table, scores
 * are skipped with one warning, and any other failure is reported.
 */
class ScoreRecorder
{
    /** Longest score name the column holds. */
    protected const MAX_NAME_LENGTH = 100;

    /** Whether the missing scores table was already reported (the recorder is a singleton). */
    protected bool $hasWarnedAboutMissingTable = false;

    /**
     * Record a score on the given call, trace or conversation, and return it,
     * or null when it could not be saved.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $name,
        int|float|bool|string $value,
        SpectraRequest|string|null $request = null,
        ?string $traceId = null,
        ?string $conversationId = null,
        ?string $comment = null,
        ScoreSource|string $source = ScoreSource::Api,
        ?Model $author = null,
        array $metadata = [],
    ): ?SpectraScore {
        $attributes = $this->attributes($name, $value, $request, $traceId, $conversationId, $comment, $source, $author, $metadata);

        if (! config('spectra.enabled', true)) {
            return null;
        }

        try {
            $score = SpectraScore::query()->create($attributes);
        } catch (QueryException $e) {
            $this->isMissingTable($e) ? $this->warnAboutMissingTable() : report($e);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        rescue(fn () => event(new ScoreRecorded($score)));

        return $score;
    }

    /**
     * Get the score's columns, checking the arguments first.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function attributes(
        string $name,
        int|float|bool|string $value,
        SpectraRequest|string|null $request = null,
        ?string $traceId = null,
        ?string $conversationId = null,
        ?string $comment = null,
        ScoreSource|string $source = ScoreSource::Api,
        ?Model $author = null,
        array $metadata = [],
    ): array {
        $name = Str::trim($name);

        throw_if(blank($name), InvalidArgumentException::class, 'A score needs a name.');
        throw_if(Str::length($name) > self::MAX_NAME_LENGTH, InvalidArgumentException::class, 'A score name is at most '.self::MAX_NAME_LENGTH.' characters.');
        throw_if(is_float($value) && ! is_finite($value), InvalidArgumentException::class, 'A score value must be a finite number.');
        throw_if(is_string($value) && blank($value), InvalidArgumentException::class, 'A score label cannot be empty.');

        $requestId = $request instanceof SpectraRequest ? $request->getKey() : $request;

        if ($request instanceof SpectraRequest) {
            $traceId ??= $request->trace_id;
            $conversationId ??= $request->conversation_id;
        }

        throw_if(
            blank($requestId) && blank($traceId) && blank($conversationId),
            InvalidArgumentException::class,
            'A score needs a call, trace or conversation to belong to. Outside a trace, pass request:, traceId: or conversationId:.',
        );

        $type = ScoreType::of($value);

        return [
            'name' => $name,
            'type' => $type,
            'value' => match ($type) {
                ScoreType::Boolean => $value ? 1.0 : 0.0,
                ScoreType::Categorical => null,
                ScoreType::Numeric => (float) $value,
            },
            'label' => $type === ScoreType::Categorical ? (string) $value : null,
            'comment' => filled($comment) ? $comment : null,
            'source' => $source instanceof ScoreSource ? $source : ScoreSource::from($source),
            'request_id' => filled($requestId) ? $requestId : null,
            'trace_id' => filled($traceId) ? $traceId : null,
            'conversation_id' => filled($conversationId) ? $conversationId : null,
            'author_type' => $author?->getMorphClass(),
            'author_id' => $author !== null ? (string) $author->getKey() : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ];
    }

    /**
     * Checked by error code, not message: Postgres quotes the failed SQL.
     */
    protected function isMissingTable(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());

        // MySQL, MariaDB and SQL Server use 42S02, Postgres 42P01; SQLite only says so in words.
        return in_array($code, ['42S02', '42P01'], true)
            || str_contains((string) ($e->errorInfo[2] ?? ''), 'no such table');
    }

    /**
     * Log once per recorder that the scores table is missing.
     */
    protected function warnAboutMissingTable(): void
    {
        if ($this->hasWarnedAboutMissingTable) {
            return;
        }

        $this->hasWarnedAboutMissingTable = true;

        Log::warning('Spectra: the spectra_scores table does not exist yet, so scores are not saved. Run `php artisan spectra:install` to publish and run the new migration.');
    }
}
