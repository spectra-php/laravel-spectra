<?php

namespace Spectra\Contracts;

use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

interface Handler
{
    public function modelType(): ModelType;

    /** @return array<string> */
    public function endpoints(): array;

    public function matchesEndpoint(string $endpoint): bool;

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics;

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string;

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string;
}
