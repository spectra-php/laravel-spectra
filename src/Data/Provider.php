<?php

declare(strict_types=1);

namespace Spectra\Data;

readonly class Provider extends DataTransferObject
{
    public function __construct(
        public string $internal_name,
        public string $display_name,
    ) {}
}
