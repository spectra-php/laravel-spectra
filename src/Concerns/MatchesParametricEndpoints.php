<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Support\Str;

/**
 * Matches endpoints that contain {placeholder} segments.
 *
 * Converts patterns like "/v1/models/{model}:generateContent"
 * into regex that matches any value in the placeholder position.
 */
trait MatchesParametricEndpoints
{
    /**
     * Determine if the request path fits one of the handler's endpoint patterns.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        foreach ($this->endpoints() as $pattern) {
            $patternRegex = Str::replaceMatches('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($pattern, '#'));

            if (Str::isMatch("#^{$patternRegex}\$#", $endpoint)) {
                return true;
            }
        }

        return false;
    }
}
