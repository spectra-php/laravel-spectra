<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\DocumentMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Mistral Document AI OCR (`POST /v1/ocr`), billed per page.
 *
 * The page count comes from `usage_info.pages_processed`. Requests with a
 * document or bounding-box annotation format bill a higher "annotated page"
 * rate, which the catalog does not model (see MistralPricing).
 */
class OcrHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Mistral OCR requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the Mistral OCR endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v1/ocr'];
    }

    /**
     * Build the processed page count of a Mistral OCR response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $pages = $responseData['usage_info']['pages_processed']
            ?? (is_array($responseData['pages'] ?? null) ? count($responseData['pages']) : 0);

        return new Metrics(
            document: new DocumentMetrics(pages: Payload::int($pages)),
        );
    }

    /**
     * Get the markdown of every OCR page, joined by blank lines.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        if (! is_array($response['pages'] ?? null)) {
            return null;
        }

        $markdownPages = collect($response['pages'])
            ->map(fn (mixed $page) => is_array($page) && is_string($page['markdown'] ?? null) ? $page['markdown'] : null)
            ->filter()
            ->all();

        return $markdownPages !== [] ? implode("\n\n", $markdownPages) : null;
    }

    /**
     * Determine if the body is a Mistral OCR response with pages and usage info.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return isset($data['pages'], $data['usage_info']);
    }
}
