<?php

namespace Spectra\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Models\SpectraRequest;

readonly class DateRange
{
    public function __construct(
        public ?Carbon $start,
        public ?Carbon $end,
    ) {}

    public static function fromRequest(Request $request, string $period = 'month'): self
    {
        $period = $request->input('period', $period);

        if ($period === 'all') {
            return new self(null, null);
        }

        if ($period === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            try {
                $start = Carbon::parse((string) $request->input('start_date'))->startOfDay();
                $end = Carbon::parse((string) $request->input('end_date'))->endOfDay();

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
                $start = Carbon::parse((string) $request->input('start_date'))->startOfDay();
                $end = Carbon::parse((string) $request->input('end_date'))->endOfDay();

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
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        return new self($start, null);
    }

    /**
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
}
