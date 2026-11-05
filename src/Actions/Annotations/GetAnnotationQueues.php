<?php

declare(strict_types=1);

namespace Spectra\Actions\Annotations;

use Illuminate\Support\Facades\Schema;
use Spectra\Data\AnnotationQueueSummary;
use Spectra\Enums\AnnotationStatus;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Support\Annotations\AnnotationQueue;
use Spectra\Support\Annotations\AnnotationQueues;
use stdClass;

/**
 * The Review screen: every configured queue, with how many traces wait in
 * it, were reviewed and were skipped.
 */
class GetAnnotationQueues
{
    /**
     * Create a new get annotation queues action instance.
     */
    public function __construct(
        private readonly AnnotationQueues $queues,
    ) {}

    /**
     * Get every queue with its counts; all 0 until the annotation migration runs.
     *
     * @return array<int, AnnotationQueueSummary>
     */
    public function __invoke(): array
    {
        $counts = $this->counts();

        return collect($this->queues->all())
            ->map(fn (AnnotationQueue $queue) => $this->summary($queue, $counts))
            ->values()
            ->all();
    }

    /**
     * Count the items each queue has per status, keyed by "queue|status".
     *
     * None until the annotation migration runs.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        if (! $this->migrated()) {
            return [];
        }

        return SpectraAnnotationItem::query()->toBase()
            ->select('queue', 'status')
            ->selectRaw('COUNT(*) as item_count')
            ->groupBy('queue', 'status')
            ->get()
            ->mapWithKeys(fn (stdClass $row) => ["{$row->queue}|{$row->status}" => (int) $row->item_count])
            ->all();
    }

    /**
     * Build the summary of one queue with its counts.
     *
     * @param  array<string, int>  $counts  By "queue|status"
     */
    public function summary(AnnotationQueue $queue, array $counts): AnnotationQueueSummary
    {
        return new AnnotationQueueSummary(
            name: $queue->name,
            description: $queue->description,
            scores: $queue->scores,
            sample: $queue->sample,
            filter: $queue->filter,
            pending_count: $counts[$queue->name.'|'.AnnotationStatus::Pending->value] ?? 0,
            completed_count: $counts[$queue->name.'|'.AnnotationStatus::Completed->value] ?? 0,
            skipped_count: $counts[$queue->name.'|'.AnnotationStatus::Skipped->value] ?? 0,
        );
    }

    /**
     * Determine if the annotation migration has run.
     */
    public function migrated(): bool
    {
        $item = new SpectraAnnotationItem;

        return rescue(fn () => Schema::connection($item->getConnectionName())->hasTable($item->getTable()), false, report: false);
    }
}
