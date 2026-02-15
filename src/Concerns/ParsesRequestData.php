<?php

namespace Spectra\Concerns;

/**
 * Shared logic for extracting request data from Laravel HTTP client requests.
 *
 * Handles both JSON and multipart form data, extracting text fields
 * while skipping file uploads.
 */
trait ParsesRequestData
{
    /**
     * @param  \Illuminate\Http\Client\Request  $request
     * @return array<string, mixed>
     */
    protected static function parseRequestData($request): array
    {
        try {
            if ($request->isMultipart()) {
                return static::parseMultipartRequestData($request);
            }

            $body = $request->body();

            if ($body) {
                return json_decode($body, true) ?? [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @param  \Illuminate\Http\Client\Request  $request
     * @return array<string, mixed>
     */
    protected static function parseMultipartRequestData($request): array
    {
        try {
            /** @var array<int|string, mixed> $data */
            $data = $request->data();

            if (! empty($data)) {
                if (static::isGuzzleMultipartFormat($data)) {
                    return static::flattenGuzzleMultipart($data);
                }

                return $data; // @phpstan-ignore return.type
            }

            // Fall back to raw body parsing if data() is empty
            $psr = $request->toPsrRequest();
            $contentType = $psr->getHeaderLine('Content-Type');

            preg_match('/boundary=(.+?)(?:;|$)/', $contentType, $matches);
            $boundary = trim($matches[1] ?? '', '"');

            if ($boundary === '') {
                return [];
            }

            $body = $psr->getBody()->read(8192);
            $psr->getBody()->rewind();

            $fields = [];
            $parts = explode('--'.$boundary, $body);

            foreach ($parts as $part) {
                $part = ltrim($part, "\r\n");

                if ($part === '' || $part === '--' || str_starts_with($part, '--')) {
                    continue;
                }

                if (str_contains($part, 'filename=')) {
                    continue;
                }

                if (preg_match('/Content-Disposition:.*?name="([^"]+)"/i', $part, $nameMatch)) {
                    $headerEnd = strpos($part, "\r\n\r\n");

                    if ($headerEnd !== false) {
                        $value = substr($part, $headerEnd + 4);
                        $fields[$nameMatch[1]] = rtrim($value, "\r\n");
                    }
                }
            }

            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param  array<int|string, mixed>  $data */
    protected static function isGuzzleMultipartFormat(array $data): bool
    {
        if (! isset($data[0]) || ! is_array($data[0])) {
            return false;
        }

        return isset($data[0]['name']);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function flattenGuzzleMultipart(array $data): array
    {
        $fields = [];

        foreach ($data as $part) {
            if (! is_array($part) || ! isset($part['name'], $part['contents'])) {
                continue;
            }

            if (isset($part['filename']) || ! is_string($part['contents'])) {
                continue;
            }

            $fields[$part['name']] = $part['contents'];
        }

        return $fields;
    }
}
