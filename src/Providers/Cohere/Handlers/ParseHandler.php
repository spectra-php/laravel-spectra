<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\DocumentMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * Cohere Parse (`POST /v2/parse`), billed per page parsed.
 *
 * The page count comes from `meta.billed_units.pages`. The response does not
 * name the model, so it comes from the request.
 */
class ParseHandler implements Handler, MatchesResponseShape
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v2/parse'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $pages = $responseData['meta']['billed_units']['pages']
            ?? (is_array($responseData['pages'] ?? null) ? count($responseData['pages']) : 0);

        return new Metrics(
            document: new DocumentMetrics(pages: (int) $pages),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! is_array($response['pages'] ?? null)) {
            return null;
        }

        $parts = [];

        foreach ($response['pages'] as $page) {
            $content = is_array($page) ? ($page['markdown']['content'] ?? null) : null;

            if (filled($content)) {
                $parts[] = $content;
            }
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['pages'], $data['meta']['billed_units']['pages']);
    }
}
