<?php

declare(strict_types=1);

namespace Spectra\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;

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

        if ($period === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            try {
                $start = Date::parse((string) $request->input('start_date'))->startOfDay();
                $end = Date::parse((string) $request->input('end_date'))->endOfDay();

                if ($end->lt($start)) {
                    [$start, $end] = [
                        $end->copy()->startOfDay(),
                        $start->copy()->endOfDay(),
                    ];
                }

                return new self($start, $end);
            } catch (\Throwable) {
                // Fallback to preset period handling.
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            try {
                $start = Date::parse((string) $request->input('start_date'))->startOfDay();
                $end = Date::parse((string) $request->input('end_date'))->endOfDay();

                if ($end->lt($start)) {
                    [$start, $end] = [
                        $end->copy()->startOfDay(),
                        $start->copy()->endOfDay(),
                    ];
                }

                return new self($start, $end);
            } catch (\Throwable) {
                // Fallback to preset period handling.
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
     * Limit the query to rows whose date column falls in the range.
     *
     * @param  Builder<SpectraRequest>  $query
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
     * The end bound as a moment in time: a date-only string ("2026-01-31")
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
