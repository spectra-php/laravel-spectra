<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\AnnotationQueueSummary;
use Spectra\Data\AnnotationReviewItem;
use Spectra\Data\DataTransferObject;

/**
 * The next trace to review in a queue, or none when the queue is done.
 */
readonly class AnnotationItemResponse extends DataTransferObject
{
    /**
     * Create a new annotation item response instance.
     */
    public function __construct(
        public AnnotationQueueSummary $queue,
        public ?AnnotationReviewItem $item,
        public bool $can_annotate,
    ) {}
}
