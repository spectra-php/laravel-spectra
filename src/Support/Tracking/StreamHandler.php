<?php

namespace Spectra\Support\Tracking;

abstract class StreamHandler
{
    /**
     * Extract text content from a streaming chunk.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function text(array $data): ?string;

    /**
     * Extract usage/token metrics from a streaming chunk.
     *
     * Receives the current accumulated usage so providers that split
     * usage across multiple chunks (e.g. Anthropic) can accumulate.
     *
     * @param  array<string, mixed>  $data  The normalized chunk data
     * @param  array<string, mixed>  $currentUsage  Current accumulated usage
     * @return array<string, mixed> Updated usage array with prompt_tokens, completion_tokens, cached_tokens
     */
    abstract public function usage(array $data, array $currentUsage): array;

    /**
     * Extract finish reason from a streaming chunk.
     *
     * Returns null if this chunk doesn't contain a finish reason.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function finishReason(array $data): ?string;

    /**
     * Extract model and response ID from a streaming chunk.
     *
     * Returns an array with optional 'model' and 'id' keys,
     * or null if this chunk doesn't contain model info.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    abstract public function model(array $data): ?array;
}
