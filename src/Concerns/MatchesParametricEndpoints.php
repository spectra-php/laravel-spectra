<?php

namespace Spectra\Concerns;

/**
 * Matches endpoints that contain {placeholder} segments.
 *
 * Converts patterns like "/v1/models/{model}:generateContent"
 * into regex that matches any value in the placeholder position.
 */
trait MatchesParametricEndpoints
{
    public function matchesEndpoint(string $endpoint): bool
    {
        foreach ($this->endpoints() as $pattern) {
            $regex = '#^'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($pattern, '#')).'$#';

            if (preg_match($regex, $endpoint)) {
                return true;
            }
        }

        return false;
    }
}
