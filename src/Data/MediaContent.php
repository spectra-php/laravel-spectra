<?php

declare(strict_types=1);

namespace Spectra\Data;

readonly class MediaContent extends DataTransferObject
{
    public function __construct(
        public string $content,
        public string $mime_type,
        public string $filename,
    ) {}
}
