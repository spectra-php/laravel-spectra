<?php

declare(strict_types=1);

namespace Spectra\Concerns;

/**
 * Matches a request path against a handler's fixed endpoint paths, exactly.
 *
 * For paths with variable segments (a model or job id), use
 * MatchesParametricEndpoints instead.
 */
trait MatchesEndpoints
{
    /**
     * Determine if the request path is one of the handler's endpoints.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        return in_array($endpoint, $this->endpoints(), true);
    }
}
