<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\AnnotationQueueSummary;
use Spectra\Data\DataTransferObject;

/**
 * The Review screen: every configured queue with its counts.
 */
readonly class AnnotationQueuesResponse extends DataTransferObject
{
    /**
     * Create a new annotation queues response instance.
     *
     * @param  array<int, AnnotationQueueSummary>  $queues
     * @param  bool  $can_annotate  Whether the annotateSpectra gate lets the user review
     * @param  bool  $migrated  Whether the annotation migration has run
     */
    public function __construct(
        public array $queues,
        public bool $can_annotate,
        public bool $migrated,
    ) {}
}
