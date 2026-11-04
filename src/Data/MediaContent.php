<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * A stored media file ready to send to the browser: its bytes, the mime type
 * derived from the stored path, and a download name.
 */
readonly class MediaContent extends DataTransferObject
{
    /**
     * Create a new media content instance.
     */
    public function __construct(
        public string $content,
        public string $mime_type,
        public string $filename,
    ) {}
}
