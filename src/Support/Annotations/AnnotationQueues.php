<?php

declare(strict_types=1);

namespace Spectra\Support\Annotations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spectra\Enums\AnnotationStatus;
use Spectra\Enums\ScoreSource;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Support\Scoring\ScoreRecorder;
use Spectra\Support\Tracking\RecordingSwitch;
use Spectra\Support\Tracking\RequestContext;
use Throwable;

/**
 * The review queues of `spectra.annotations.queues`: puts traces in them
 * (sampled as calls are recorded, or added by hand) and saves reviews as
 * Annotation scores on the trace.
 *
 * Until the app runs the annotation migration, nothing is queued and a
 * warning is logged once; tracking is never affected.
 */
class AnnotationQueues
{
    /** Whether the missing table was already reported (bound as a singleton). */
    protected bool $hasWarnedAboutMissingTable = false;

    /**
     * Create a new annotation queues instance.
     */
    public function __construct(
        protected ScoreRecorder $scores,
    ) {}

    /**
     * Every configured queue, by name.
     *
     * @return array<string, AnnotationQueue>
     */
    public function all(): array
    {
        return collect((array) config('spectra.annotations.queues', []))
            ->filter(fn (mixed $config, int|string $name) => is_string($name) && is_array($config))
            ->map(fn (array $config, string $name) => AnnotationQueue::fromConfig($name, $config))
            ->all();
    }

    /**
     * The queue with this name, or an exception naming the configured ones.
     */
    public function find(string $name): AnnotationQueue
    {
        $queue = $this->all()[$name] ?? null;

        throw_if($queue === null, InvalidArgumentException::class, "There is no Spectra annotation queue [{$name}]. Add it under spectra.annotations.queues.");

        return $queue;
    }

    /**
     * A call was recorded: put its trace in every queue that samples it.
     */
    public function callRecorded(RequestContext $context, bool $failed): void
    {
        if (blank($context->traceId) || config('spectra.annotations.queues', []) === []) {
            return;
        }

        foreach ($this->all() as $queue) {
            if ($queue->samples($context, $failed)) {
                $this->add($queue->name, $context->traceId, $context->id);
            }
        }
    }

    /**
     * Put a trace in a queue, once: a trace already there keeps its place
     * and status. Returns whether the trace is in the queue now.
     */
    public function add(string $queue, string $traceId, ?string $requestId = null): bool
    {
        if (! config('spectra.enabled', true) || RecordingSwitch::isPaused()) {
            return false;
        }

        try {
            SpectraAnnotationItem::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'queue' => $queue,
                'trace_id' => $traceId,
                'request_id' => $requestId,
                'status' => AnnotationStatus::Pending->value,
                'created_at' => Date::now(),
            ]);
        } catch (QueryException $e) {
            $this->isMissingTable($e) ? $this->warnAboutMissingTable() : report($e);

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Save a review: each value as an Annotation score on the trace, then
     * mark the item reviewed by the reviewer.
     *
     * @param  array<string, mixed>  $values  By score name; empty ones are left out
     */
    public function review(SpectraAnnotationItem $item, array $values, ?string $comment = null, ?Model $reviewer = null): void
    {
        $queue = $this->find($item->queue);
        $scores = $queue->validate($values);

        throw_if($scores === [] && blank($comment), InvalidArgumentException::class, 'Give at least one score or a comment.');

        foreach ($scores as $name => $value) {
            $this->scores->record(
                name: $name,
                value: $value,
                request: $item->request_id,
                traceId: $item->trace_id,
                source: ScoreSource::Annotation,
                author: $reviewer,
                metadata: ['annotation_queue' => $item->queue],
            );
        }

        $this->finish($item, AnnotationStatus::Completed, $reviewer, $comment);
    }

    /**
     * Pass over an item; it leaves the pending list.
     */
    public function skip(SpectraAnnotationItem $item, ?Model $reviewer = null): void
    {
        $this->finish($item, AnnotationStatus::Skipped, $reviewer);
    }

    /**
     * Mark the item done with this status, by this reviewer.
     */
    protected function finish(SpectraAnnotationItem $item, AnnotationStatus $status, ?Model $reviewer, ?string $comment = null): void
    {
        $item->forceFill([
            'status' => $status,
            'comment' => filled($comment) ? $comment : null,
            'reviewer_type' => $reviewer?->getMorphClass(),
            'reviewer_id' => $reviewer !== null ? (string) $reviewer->getKey() : null,
            'reviewed_at' => Date::now(),
        ])->save();
    }

    /**
     * Checked by error code, not message: Postgres quotes the failed SQL.
     */
    protected function isMissingTable(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['42S02', '42P01'], true)
            || str_contains((string) ($e->errorInfo[2] ?? ''), 'no such table');
    }

    /**
     * Log once that the annotation table is missing.
     */
    protected function warnAboutMissingTable(): void
    {
        if ($this->hasWarnedAboutMissingTable) {
            return;
        }

        $this->hasWarnedAboutMissingTable = true;

        Log::warning('Spectra: the spectra_annotation_items table does not exist yet, so no trace is queued for review. Run `php artisan spectra:install` to publish and run the new migration.');
    }
}
