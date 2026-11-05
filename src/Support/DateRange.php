<?php

declare(strict_types=1);

namespace Spectra\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * The period a dashboard screen asks for, read from the request's `period`,
 * `start_date` and `end_date`, and applied to a query's `created_at`.
 *
 * Also normalises the date bounds users pass to the public usage APIs, so a
 * date-only end ("2026-01-31") covers that whole day instead of stopping at
 * its first second.
 */
readonly class DateRange
{
    /**
     * Create a new date range instance; a null start means all time.
     */
    public function __construct(
        public ?CarbonInterface $start,
        public ?CarbonInterface $end,
    ) {}

    /**
     * Create a date range from the dashboard's period or custom start and end dates.
     */
    public static function fromRequest(Request $request, string $period = 'month'): self
    {
        $period = $request->input('period', $period);

        if ($period === 'all') {
            return new self(null, null);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            // Dates that don't parse fall back to the preset period below.
            $range = rescue(
                fn () => self::fromDates((string) $request->input('start_date'), (string) $request->input('end_date')),
                report: false,
            );

            if ($range !== null) {
                return $range;
            }
        }

        $start = match ($period) {
            'today' => today(),
            'week' => now()->startOfWeek(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        return new self($start, null);
    }

    /**
     * Create a date range covering the whole days from start to end, swapping
     * the two when they are given backwards.
     */
    private static function fromDates(string $startDate, string $endDate): self
    {
        $start = Date::parse($startDate)->startOfDay();
        $end = Date::parse($endDate)->endOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [
                $end->copy()->startOfDay(),
                $start->copy()->endOfDay(),
            ];
        }

        return new self($start, $end);
    }

    /**
     * Limit the query to rows whose date column falls in the range.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function apply(Builder $query, string $column = 'created_at'): void
    {
        if ($this->start === null) {
            return;
        }

        if ($this->end !== null) {
            $query->whereBetween($column, [$this->start, $this->end]);

            return;
        }

        $query->where($column, '>=', $this->start);
    }

    /**
     * Get the end bound as a moment in time: a date-only string ("2026-01-31")
     * means the end of that day, since a plain parse would stop at midnight
     * and drop the whole last day.
     */
    public static function inclusiveEnd(CarbonInterface|string $end): CarbonInterface
    {
        if ($end instanceof CarbonInterface) {
            return $end;
        }

        $parsed = Date::parse($end);

        return Str::isMatch('/^\d{4}-\d{2}-\d{2}$/', Str::trim($end)) ? $parsed->endOfDay() : $parsed;
    }
}
