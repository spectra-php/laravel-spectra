<?php

declare(strict_types=1);

namespace Spectra\Support\Errors;

use Illuminate\Support\Str;

/**
 * Groups failed AI calls: two failures with the same provider, status code,
 * error type and message, once the parts that change from call to call are
 * taken out (ids, numbers, URLs, emails, masked keys), share a fingerprint.
 * "Please try again in 2s" and "Please try again in 14s" are one rate-limit
 * group.
 *
 * The model is left out, even where the message names it ("Rate limit
 * reached for gpt-4o"), so one group shows every model a failure hit. A
 * message masked by redaction groups with the same message unmasked.
 */
class ErrorFingerprint
{
    /** Characters of the cleaned message that count; the rest of a long message rarely tells failures apart. */
    protected const MESSAGE_LENGTH = 500;

    /**
     * Replacements, in order: masked values first, then the specific shapes
     * (URLs, emails, UUIDs, prefixed ids) before the plain numbers inside them.
     */
    protected const PATTERNS = [
        '/\[REDACTED:(\w+)\]/' => '<$1>',
        // A secret the provider masked itself: sk-proj-****abcd.
        '/\b[A-Za-z]+(?:-[A-Za-z]+)*-\*{3,}[A-Za-z0-9]*/' => '<masked>',
        '#\bhttps?://\S+#i' => '<url>',
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '<email>',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '<ip_address>',
        // Provider ids: req_8f3a..., org-3kTq..., chatcmpl-AbC1...
        '/\b([A-Za-z]+[_\-])+(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{6,}\b/' => '<id>',
        '/\b[0-9a-f]{16,}\b/i' => '<id>',
        '/\d+(?:[.,]\d+)*/' => '<n>',
        '/\s+/' => ' ',
    ];

    /**
     * The fingerprint of a failure: 16 hex characters.
     *
     * @param  array<int, string|null>  $models  The call's model names (id and display name), taken out of the message
     */
    public function for(string $provider, ?int $statusCode, ?string $errorType, ?string $message, array $models = []): string
    {
        return mb_substr(hash('sha256', implode('|', [$provider, $statusCode ?? '', $errorType ?? '', $this->normalize($message, $models)])), 0, 16);
    }

    /**
     * The message with the parts that change between calls replaced.
     *
     * @param  array<int, string|null>  $models
     */
    public function normalize(?string $message, array $models = []): string
    {
        $normalized = (string) $message;

        // Longest first, so "gpt-4o-mini" isn't left as "<model>-mini".
        foreach (collect($models)->filter()->unique()->sortByDesc(fn (string $model) => mb_strlen($model)) as $model) {
            $normalized = str_ireplace($model, '<model>', $normalized);
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $normalized = Str::replaceMatches($pattern, $replacement, $normalized) ?? $normalized;
        }

        return mb_substr(trim($normalized), 0, self::MESSAGE_LENGTH);
    }
}
