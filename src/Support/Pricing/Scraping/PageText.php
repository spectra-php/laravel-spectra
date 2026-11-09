<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

use Illuminate\Support\Str;

/**
 * The scraped pages as text, to check extracted prices against.
 *
 * LLM extraction sometimes copies a price from a neighbouring row or invents
 * one, so a price only counts when the page prints it next to the model:
 *
 * - on a table row naming the model, or on the rows right below it that
 *   continue it (a "Text" or "Audio" row without a model id of its own);
 * - otherwise (a heading or card followed by its price table) within WINDOW
 *   lines below the model's name.
 *
 * Numbers inside the model's name ("gpt-6", "3.1") never count, and long
 * prose lines are ignored: they mention models and numbers ("5-minute",
 * "2^30") that are not prices.
 */
final class PageText
{
    /** Lines below a model's name (outside a table) that may hold its prices. */
    public const WINDOW = 30;

    /** Lines longer than this are prose, not price tables. */
    protected const MAX_LINE_LENGTH = 500;

    /** @var list<string> Lowercased lines. */
    protected array $lines = [];

    /**
     * Create the page text from markdown, lowercased line by line with prose lines blanked.
     */
    public function __construct(string $markdown)
    {
        foreach (Str::of($markdown)->split('/\R/') as $line) {
            $this->lines[] = strlen($line) > self::MAX_LINE_LENGTH ? '' : strtolower(trim($line));
        }
    }

    /**
     * Determine if the page prints this amount next to one of the model's names.
     *
     * @param  list<string>  $names  Model ids and display names.
     * @param  float  $amount  As printed on the page.
     */
    public function confirms(array $names, float $amount): bool
    {
        return $this->find($names, $amount) !== null;
    }

    /**
     * Determine if the page prints this model id as a name of its own: not
     * inside a longer id or a URL path ("models/ocr-4-1" doesn't count).
     */
    public function mentions(string $id): bool
    {
        $id = strtolower(trim($id));

        if (strlen($id) < 2) {
            return false;
        }

        $pattern = $this->namePattern($id);

        return collect($this->lines)->contains(fn (string $line) => Str::isMatch($pattern, $line));
    }

    /**
     * Get the pattern that finds a lowercased name standing on its own.
     */
    protected function namePattern(string $name): string
    {
        $quotedName = preg_quote($name, '/');

        return "/(?<![\w.\/-]){$quotedName}(?![\w-]|\.\d)/";
    }

    /**
     * Get the modality a line labels this amount with ("$12.00 (audio)" →
     * "audio"), or null when it doesn't label it.
     */
    public static function modalityAfter(string $line, float $amount): ?string
    {
        preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*\(\s*(text|audio|image|video)\b/i', $line, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $number, $modality]) {
            if (abs((float) str_replace(',', '', $number) - $amount) < 0.0000001) {
                return strtolower($modality);
            }
        }

        return null;
    }

    /**
     * Get the unit a line prices this amount per when it isn't tokens or
     * characters ("$0.0004 /Min" → "min"), or null when it doesn't say.
     */
    public static function unitAfter(string $line, float $amount): ?string
    {
        preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*(?:\/|per\b)\s*(min|minutes?|sec|seconds?|hours?|images?|img|pages?|requests?)\b/i', $line, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $number, $unit]) {
            if (abs((float) str_replace(',', '', $number) - $amount) < 0.0000001) {
                return strtolower($unit);
            }
        }

        return null;
    }

    /**
     * Get the quantity a line says its prices are for, from markers like "/ MTok",
     * "per 1K characters" or "$13 /M Chars": 1000000, 1000, or null when the
     * line doesn't say (or says both).
     */
    public static function quantityOn(string $line): ?int
    {
        $isPerMillion = Str::isMatch('/(per\s*1\s*m\b|\/\s*1?\s*m\b|\b1m\b|\bmtok\b|\bm\s*(tokens|chars|characters)\b|million)/i', $line);
        $isPerThousand = Str::isMatch('/(per\s*1\s*k\b|\/\s*1?\s*k\b|\bktok\b|\bk\s*(tokens|chars|characters)\b|thousand|per\s*1,000\b)/i', $line);

        return match (true) {
            $isPerMillion && ! $isPerThousand => 1_000_000,
            $isPerThousand && ! $isPerMillion => 1000,
            default => null,
        };
    }

    /**
     * Find the line that prints this amount next to one of the model's names.
     *
     * @param  list<string>  $names  Model ids and display names.
     * @param  float  $amount  As printed on the page.
     */
    public function find(array $names, float $amount): ?string
    {
        $patterns = [];
        foreach (collect($names)->map(fn (string $name) => strtolower(trim($name)))->filter()->uniqueStrict() as $name) {
            if (strlen($name) >= 2) {
                $patterns[] = $this->namePattern($name);
            }
        }

        if ($patterns === []) {
            return null;
        }

        foreach ($this->lines as $index => $line) {
            $rest = Str::replaceMatches($patterns, ' ', $line);

            if ($rest === null || $rest === $line) {
                continue;
            }

            $ownNumbers = $this->extractNumbers($rest);

            if ($this->isTableRow($line) && $ownNumbers !== []) {
                if ($this->contains($ownNumbers, $amount)) {
                    return $line;
                }

                $continuation = $this->continuationContaining($index, $amount);

                if ($continuation !== null) {
                    return $continuation;
                }

                continue;
            }

            if ($this->contains($ownNumbers, $amount)) {
                return $line;
            }

            for ($i = $index + 1; $i <= $index + self::WINDOW && $i < count($this->lines); $i++) {
                if ($this->contains($this->extractNumbers($this->lines[$i]), $amount)) {
                    return $this->lines[$i];
                }
            }
        }

        return null;
    }

    /**
     * Find the continuation row that prints the amount: a table row right
     * below a model's row whose first cell is a label ("Text", "Audio"), not
     * another model id.
     */
    protected function continuationContaining(int $index, float $amount): ?string
    {
        for ($i = $index + 1; $i < count($this->lines) && $this->isTableRow($this->lines[$i]); $i++) {
            $firstCell = trim(Str::before(trim($this->lines[$i], '| '), '|'));

            if (blank($firstCell) || ! Str::isMatch('/^[a-z ]+$/', $firstCell)) {
                return null;
            }

            if ($this->contains($this->extractNumbers($this->lines[$i]), $amount)) {
                return $this->lines[$i];
            }
        }

        return null;
    }

    /**
     * Determine if a line is a markdown table row, not a header separator.
     */
    protected function isTableRow(string $line): bool
    {
        return Str::startsWith($line, '|') && ! Str::isMatch('/^\|[\s:|-]+\|?$/', $line);
    }

    /**
     * Get the numbers printed on a line, with thousands separators removed.
     *
     * @return array<int, float>
     */
    protected function extractNumbers(string $line): array
    {
        return Str::matchAll('/(?<![\w.])\d[\d,]*(?:\.\d+)?/', $line)
            ->map(fn (string $number) => (float) Str::replace(',', '', $number))
            ->all();
    }

    /**
     * Determine if one of the numbers equals the amount, allowing for float noise.
     *
     * @param  array<int, float>  $numbers
     */
    protected function contains(array $numbers, float $amount): bool
    {
        foreach ($numbers as $number) {
            if (abs($number - $amount) < 0.0000001) {
                return true;
            }
        }

        return false;
    }
}
