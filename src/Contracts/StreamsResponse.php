<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Spectra\Support\Tracking\StreamHandler;

interface StreamsResponse
{
    public function streamingHandler(): StreamHandler;
}
