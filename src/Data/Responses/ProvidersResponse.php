<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Provider;

readonly class ProvidersResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, Provider>  $providers
     */
    public function __construct(
        public Collection $providers,
    ) {}
}
