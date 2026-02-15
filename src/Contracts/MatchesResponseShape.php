<?php

namespace Spectra\Contracts;

interface MatchesResponseShape
{
    /**
     * Whether this handler can process the given response shape.
     *
     * Used when no endpoint is available (fallback) and for disambiguating
     * when multiple handlers match the same endpoint.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool;
}
