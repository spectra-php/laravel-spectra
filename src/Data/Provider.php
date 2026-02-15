<?php

namespace Spectra\Data;

readonly class Provider extends DataTransferObject
{
    public function __construct(
        public string $internal_name,
        public string $display_name,
        public ?string $logo_svg,
    ) {}
}
