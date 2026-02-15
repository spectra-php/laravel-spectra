<?php

namespace Spectra\Concerns;

trait MatchesEndpoints
{
    public function matchesEndpoint(string $endpoint): bool
    {
        return in_array($endpoint, $this->endpoints(), true);
    }
}
