<?php

declare(strict_types=1);

namespace Spectra\Support\Redaction;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Masks personal data and secrets in everything Spectra stores that can hold
 * free text: request and response bodies, error messages, span attributes
 * and score comments. Off by default; `spectra.storage.redaction` turns it
 * on and picks the built-in patterns, custom patterns, and a callback.
 *
 * Only string values are redacted, never array keys, so the payload keeps
 * its shape and the dashboard can still read it.
 */
class Redactor
{
    /**
     * Built-in patterns, by the name used in config and in the mask
     * (`[REDACTED:email]`). Card numbers are checked with Luhn as well, so an
     * order id with 16 digits is left alone.
     */
    public const PATTERNS = [
        'email' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
        'credit_card' => '/\b(?:\d[ \-]?){12,18}\d\b/',
        'api_key' => '/\b(?:sk-(?:proj-|ant-)?[A-Za-z0-9_\-]{20,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{36,}|xox[baprs]-[A-Za-z0-9\-]{10,}|AIza[0-9A-Za-z_\-]{35})\b|Bearer\s+[A-Za-z0-9._\-]{20,}/',
        'ip_address' => '/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/',
        'phone' => '/(?<![\w.])\+\d[\d\s().\-]{7,}\d\b/',
    ];

    /** A callback that redacts a string after the patterns, set with Spectra::redactUsing(). */
    protected ?Closure $callback = null;

    /**
     * Redact every string with this callback too, after the patterns run.
     *
     * @param  Closure(string): string  $callback
     */
    public function using(Closure $callback): void
    {
        $this->callback = $callback;
    }

    /**
     * Determine if redaction is turned on.
     */
    public function isEnabled(): bool
    {
        return (bool) config('spectra.storage.redaction.enabled', false);
    }

    /**
     * Redact a value: a string, or every string inside an array, at any depth.
     * Returned as is when redaction is off.
     */
    public function redact(mixed $value): mixed
    {
        if (! $this->isEnabled()) {
            return $value;
        }

        return $this->walk($value, $this->patterns());
    }

    /**
     * Redact strings in the value, keeping array keys and other types.
     *
     * @param  array<string, string>  $patterns
     */
    protected function walk(mixed $value, array $patterns): mixed
    {
        if (is_array($value)) {
            return Arr::map($value, fn (mixed $item) => $this->walk($item, $patterns));
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        foreach ($patterns as $name => $pattern) {
            // A pattern that fails on this input (bad UTF-8, backtracking limit) leaves it unchanged.
            $value = ($name === 'credit_card'
                ? Str::replaceMatches($pattern, fn (array $match) => $this->isCardNumber($match[0]) ? '[REDACTED:credit_card]' : $match[0], $value)
                : Str::replaceMatches($pattern, "[REDACTED:{$name}]", $value)) ?? $value;
        }

        return $this->callback !== null ? (string) ($this->callback)($value) : $value;
    }

    /**
     * The built-in patterns named in config, then the custom ones.
     *
     * @return array<string, string>
     */
    protected function patterns(): array
    {
        $names = (array) config('spectra.storage.redaction.patterns', collect(self::PATTERNS)->keys()->all());
        $patterns = [];

        foreach ($names as $name) {
            throw_unless(is_string($name) && isset(self::PATTERNS[$name]), InvalidArgumentException::class, 'Unknown Spectra redaction pattern ['.(is_scalar($name) ? $name : gettype($name)).']. Use '.collect(self::PATTERNS)->keys()->implode(', ').', or add your own under redaction.custom.');

            $patterns[$name] = self::PATTERNS[$name];
        }

        foreach ((array) config('spectra.storage.redaction.custom', []) as $name => $pattern) {
            if (is_string($name) && is_string($pattern) && $pattern !== '') {
                $patterns[$name] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Determine if a run of digits is a card number by the Luhn checksum.
     */
    protected function isCardNumber(string $candidate): bool
    {
        $digits = Str::replaceMatches('/\D/', '', $candidate) ?? '';
        $length = Str::length($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        foreach (collect(str_split($digits))->reverse()->values() as $position => $digit) {
            $value = (int) $digit;

            if ($position % 2 === 1) {
                $value *= 2;
                $value = $value > 9 ? $value - 9 : $value;
            }

            $sum += $value;
        }

        return $sum % 10 === 0;
    }
}
