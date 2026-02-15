<?php

namespace Spectra\Contracts;

use Spectra\Support\Tracking\StreamHandler;

interface StreamsResponse
{
    public function streamingHandler(): StreamHandler;
}
