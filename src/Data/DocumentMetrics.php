<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Page counts for document endpoints that bill per page (e.g. OCR).
 */
readonly class DocumentMetrics extends DataTransferObject
{
    /**
     * Create a new document metrics instance.
     */
    public function __construct(
        public int $pages = 0,
    ) {}
}
