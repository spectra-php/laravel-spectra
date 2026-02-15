<?php

namespace Spectra\Data;

readonly class ImageMetrics extends DataTransferObject
{
    public function __construct(
        public int $count = 0,
    ) {}

}
