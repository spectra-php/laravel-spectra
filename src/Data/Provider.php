<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * A provider the dashboard knows, built in or user-registered: its slug and
 * display name.
 */
readonly class Provider extends DataTransferObject
{
    public function __construct(
        public string $internal_name,
        public string $display_name,
    ) {}
}
