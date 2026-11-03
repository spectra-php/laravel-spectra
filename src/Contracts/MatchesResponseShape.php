<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers that recognise their response by its shape, for
 * endpoints shared by several handlers (an image answer on a chat endpoint)
 * and for tracking without an endpoint (`Spectra::track()`).
 */
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
