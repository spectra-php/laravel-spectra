<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class ProvidersResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, \Spectra\Data\Provider>  $providers
     */
    public function __construct(
        public Collection $providers,
    ) {}
}
