<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Http\Client\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Shared logic for extracting request data from outgoing provider requests.
 *
 * Handles JSON, form and multipart bodies, extracting text fields while
 * skipping file uploads. A body that cannot be rewound is never read, so the
 * request still goes out intact.
 */
trait ParsesRequestData
{
    /**
     * @return array<string, mixed>
     */
    protected static function parsePsrRequestData(RequestInterface $request): array
    {
        try {
            $body = $request->getBody();

            if (! $body->isSeekable()) {
                return [];
            }

            $contentType = $request->getHeaderLine('Content-Type');

            if (str_contains($contentType, 'multipart/form-data')) {
                return static::parseMultipartStream($body, $contentType);
            }

            $contents = (string) $body;
            $body->rewind();

            if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($contents, $fields);

                return $fields; // @phpstan-ignore return.type
            }

            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Text fields of a multipart body. Only the first and last 64 KB are read:
     * text fields sit before or after the uploaded file, and the file itself
     * can be large.
     *
     * @return array<string, mixed>
     */
    protected static function parseMultipartStream(StreamInterface $body, string $contentType): array
    {
        preg_match('/boundary=(.+?)(?:;|$)/', $contentType, $matches);
        $boundary = trim($matches[1] ?? '', '"');

        if (blank($boundary)) {
            return [];
        }

        $window = 65536;
        $size = $body->getSize();

        $body->rewind();
        $head = $body->read($window);

        $tail = '';
        if ($size !== null && $size > $window * 2) {
            $body->seek($size - $window);
            $tail = $body->read($window);
        } elseif ($size === null || $size > $window) {
            $tail = $body->getContents();
            $head .= $tail;
            $tail = '';
        }

        $body->rewind();

        return array_merge(
            static::multipartTextFields($head, $boundary),
            static::multipartTextFields($tail, $boundary),
        );
    }

    /**
     * @return array<string, string>
     */
    protected static function multipartTextFields(string $body, string $boundary): array
    {
        $fields = [];

        foreach (explode('--'.$boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");

            if (blank($part) || str_starts_with($part, '--') || str_contains($part, 'filename=')) {
                continue;
            }

            if (! preg_match('/^Content-Disposition:.*?name="([^"]+)"/im', $part, $nameMatch)) {
                continue;
            }

            $headerEnd = strpos($part, "\r\n\r\n");

            if ($headerEnd !== false) {
                $fields[$nameMatch[1]] = rtrim(substr($part, $headerEnd + 4), "\r\n");
            }
        }

        return $fields;
    }

    /**
     * @param  Request  $request
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
     * @param  Request  $request
     * @return array<string, mixed>
     */
    protected static function parseMultipartRequestData($request): array
    {
        try {
            /** @var array<int|string, mixed> $data */
            $data = $request->data();

            if (filled($data)) {
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

            if (blank($boundary)) {
                return [];
            }

            $body = $psr->getBody()->read(8192);
            $psr->getBody()->rewind();

            $fields = [];
            $parts = explode('--'.$boundary, $body);

            foreach ($parts as $part) {
                $part = ltrim($part, "\r\n");

                if (blank($part) || $part === '--' || str_starts_with($part, '--')) {
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
