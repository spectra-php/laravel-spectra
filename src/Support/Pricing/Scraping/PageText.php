<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Scraping;

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

    public function __construct(string $markdown)
    {
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $this->lines[] = strlen($line) > self::MAX_LINE_LENGTH ? '' : strtolower(trim($line));
        }
    }

    /**
     * Whether the page prints this amount next to one of the model's names.
     *
     * @param  list<string>  $names  Model ids and display names.
     * @param  float  $amount  As printed on the page.
     */
    public function confirms(array $names, float $amount): bool
    {
        return $this->find($names, $amount) !== null;
    }

    /**
     * The quantity a line says its prices are for, from markers like "/ MTok",
     * "per 1K characters" or "$13 /M Chars": 1000000, 1000, or null when the
     * line doesn't say (or says both).
     */
    public static function quantityOn(string $line): ?int
    {
        $perMillion = preg_match('/(per\s*1\s*m\b|\/\s*1?\s*m\b|\b1m\b|\bmtok\b|\bm\s*(tokens|chars|characters)\b|million)/i', $line) === 1;
        $perThousand = preg_match('/(per\s*1\s*k\b|\/\s*1?\s*k\b|\bktok\b|\bk\s*(tokens|chars|characters)\b|thousand|per\s*1,000\b)/i', $line) === 1;

        return match (true) {
            $perMillion && ! $perThousand => 1_000_000,
            $perThousand && ! $perMillion => 1000,
            default => null,
        };
    }

    /**
     * The line that prints this amount next to one of the model's names.
     *
     * @param  list<string>  $names  Model ids and display names.
     * @param  float  $amount  As printed on the page.
     */
    public function find(array $names, float $amount): ?string
    {
        $patterns = [];
        foreach (array_unique(array_filter(array_map(fn (string $name) => strtolower(trim($name)), $names))) as $name) {
            if (strlen($name) >= 2) {
                $patterns[] = '/(?<![\w.\/-])'.preg_quote($name, '/').'(?![\w-]|\.\d)/';
            }
        }

        if ($patterns === []) {
            return null;
        }

        foreach ($this->lines as $index => $line) {
            $rest = preg_replace($patterns, ' ', $line);

            if ($rest === null || $rest === $line) {
                continue;
            }

            $own = $this->numbers($rest);

            if ($this->isTableRow($line) && $own !== []) {
                if ($this->contains($own, $amount)) {
                    return $line;
                }

                $continuation = $this->continuationContaining($index, $amount);

                if ($continuation !== null) {
                    return $continuation;
                }

                continue;
            }

            if ($this->contains($own, $amount)) {
                return $line;
            }

            for ($i = $index + 1; $i <= $index + self::WINDOW && $i < count($this->lines); $i++) {
                if ($this->contains($this->numbers($this->lines[$i]), $amount)) {
                    return $this->lines[$i];
                }
            }
        }

        return null;
    }

    /**
     * Table rows right below a model's row that continue it: their first
     * cell is a label ("Text", "Audio"), not another model id.
     */
    protected function continuationContaining(int $index, float $amount): ?string
    {
        for ($i = $index + 1; $i < count($this->lines) && $this->isTableRow($this->lines[$i]); $i++) {
            $firstCell = trim(explode('|', trim($this->lines[$i], '| '))[0]);

            if ($firstCell === '' || preg_match('/^[a-z ]+$/', $firstCell) !== 1) {
                return null;
            }

            if ($this->contains($this->numbers($this->lines[$i]), $amount)) {
                return $this->lines[$i];
            }
        }

        return null;
    }

    protected function isTableRow(string $line): bool
    {
        return str_starts_with($line, '|') && ! preg_match('/^\|[\s:|-]+\|?$/', $line);
    }

    /**
     * @return list<float>
     */
    protected function numbers(string $line): array
    {
        preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?/', $line, $matches);

        return array_map(fn (string $number) => (float) str_replace(',', '', $number), $matches[0]);
    }

    /**
     * @param  list<float>  $numbers
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
