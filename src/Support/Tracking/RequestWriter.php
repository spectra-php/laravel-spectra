<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Spectra\Contracts\RequestExporter;
use Spectra\Events\RequestTracked;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetEnforcer;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;

/**
 * Writes one tracked request to the database and keeps the daily stats,
 * budgets and integrations in step with it.
 *
 * Async jobs (OpenAI background responses and videos, Replicate and fal
 * predictions) are fetched several times under the same provider response id.
 * Those writes update the existing row instead of adding one, and only the
 * change is added to the daily stats, so a job polled ten times still counts
 * as one request with one cost.
 *
 * A queued write carries its row id, so a retried job finds the row an
 * earlier attempt saved and changes nothing. Stats, budget alerts and
 * exports run after the row is saved and report their own failures: one
 * of them failing must neither lose the row nor make the job save it again.
 *
 * Shared by the synchronous path and PersistSpectraRequestJob.
 */
class RequestWriter
{
    /** Metrics compared to decide whether an update changed anything worth reporting. */
    protected const TRACKED_METRICS = [
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'image_count',
        'video_count',
        'page_count',
        'duration_seconds',
        'input_characters',
        'total_cost_in_cents',
        'status_code',
    ];

    public function __construct(
        protected StatsAggregator $statsAggregator,
        protected RequestTransformer $transformer,
        protected RequestExporter $exporter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  bool  $export  Whether to export to integrations synchronously (the
     *                        persister handles queued export itself).
     */
    public function write(array $attributes, array $tags = [], bool $export = true): SpectraRequest
    {
        [$request, $previous] = $this->save($attributes);

        if (filled($tags)) {
            $request->attachTags($tags);
        }

        if ($previous === null) {
            rescue(fn () => $this->statsAggregator->recordRequest($request));
        } else {
            rescue(fn () => $this->statsAggregator->recordChange($request, $previous));
        }

        $changed = $previous === null || $this->hasChanged($request, $previous);

        if ($changed && config('spectra.budget.enabled', true)) {
            rescue(fn () => app(BudgetEnforcer::class)->reportRecordedRequest($request));
        }

        if ($changed && $export) {
            rescue(fn () => $this->export($request));
        }

        return $request;
    }

    public function export(SpectraRequest $request): void
    {
        $data = $this->transformer->transform($request);

        RequestTracked::dispatch($data);

        if (config('spectra.integrations.opentelemetry.enabled')) {
            $this->exporter->export($data);
        }
    }

    /**
     * Insert the row, or update the row that already holds this provider
     * response id or row id. Returns the saved row and, for updates, its
     * previous values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: SpectraRequest, 1: SpectraRequest|null}
     */
    protected function save(array $attributes): array
    {
        $existing = $this->findExisting($attributes);

        if ($existing === null) {
            try {
                return [SpectraRequest::create($attributes), null];
            } catch (UniqueConstraintViolationException $e) {
                // Another worker saved the same response id or row id first.
                $existing = $this->findExisting($attributes) ?? throw $e;
            }
        }

        $previous = clone $existing;

        // Keep the original row identity and start time. A status poll carries
        // no request body, so keep the one from the request that started the job.
        $keep = ['id', 'created_at'];

        if (empty($attributes['request']) && ! empty($existing->getAttribute('request'))) {
            $keep[] = 'request';
        }

        // Never replace a known model with "unknown" from a poll that couldn't name it.
        if (($attributes['model'] ?? null) === 'unknown' && $existing->getAttribute('model') !== 'unknown') {
            $keep[] = 'model';
        }

        $existing->fill(Arr::except($attributes, $keep))->save();

        return [$existing, $previous];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function findExisting(array $attributes): ?SpectraRequest
    {
        $responseId = $attributes['response_id'] ?? null;

        if (filled($responseId)) {
            $existing = SpectraRequest::query()->where('response_id', $responseId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $id = $attributes['id'] ?? null;

        if (blank($id)) {
            return null;
        }

        return SpectraRequest::query()->whereKey($id)->first();
    }

    protected function hasChanged(SpectraRequest $request, SpectraRequest $previous): bool
    {
        foreach (self::TRACKED_METRICS as $field) {
            if ((string) $request->getAttribute($field) !== (string) $previous->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }
}
