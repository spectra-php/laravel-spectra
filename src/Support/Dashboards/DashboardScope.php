<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * The one trackable a shared dashboard's viewer may see. Every query behind these
 * pages starts here, and a scope comes only from the saved dashboard and the
 * logged-in viewer, never from the request. These queries are their own and share
 * nothing with Spectra's main dashboard, so a change there can't reach a viewer.
 *
 * A trace or a conversation can hold calls from more than one trackable, and
 * spans have no trackable at all, so one counts as the viewer's only when every
 * call in it is theirs. Anything mixed or partly unattributed stays hidden.
 */
readonly class DashboardScope
{
    /** SQL for the spend of a group of calls: successful ones only. */
    public const COST_SQL = 'COALESCE(SUM(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN total_cost_in_cents ELSE 0 END), 0)';

    /** SQL for the average latency of a group of calls: successful ones only. */
    public const LATENCY_SQL = 'AVG(CASE WHEN '.SpectraRequest::SUCCESSFUL_SQL.' THEN latency_ms END)';

    /** SQL for the tokens of a group of calls. */
    public const TOKENS_SQL = 'COALESCE(SUM(prompt_tokens + completion_tokens), 0)';

    /** SQL for how many calls of a group failed. */
    public const FAILED_SQL = 'COALESCE(SUM(CASE WHEN '.SpectraRequest::FAILED_SQL.' THEN 1 ELSE 0 END), 0)';

    public string $type;

    public string $id;

    /**
     * Create a new dashboard scope instance.
     */
    public function __construct(public Model $trackable)
    {
        $this->type = $trackable->getMorphClass();
        $this->id = (string) $trackable->getKey();
    }

    /**
     * Get a query for the trackable's own calls.
     *
     * @return Builder<SpectraRequest>
     */
    public function calls(?DateRange $dateRange = null): Builder
    {
        $query = SpectraRequest::query()
            ->where('trackable_type', $this->type)
            ->where('trackable_id', $this->id);

        $dateRange?->apply($query);

        return $query;
    }

    /**
     * Leave out the rows of a trace or conversation holding anyone else's call.
     *
     * @param  Builder<SpectraRequest>  $query
     * @param  'trace_id'|'conversation_id'  $column
     * @return Builder<SpectraRequest>
     */
    public function withoutShared(Builder $query, string $column): Builder
    {
        $table = (new SpectraRequest)->getTable();

        return $query->whereNotExists(function (QueryBuilder $others) use ($table, $column) {
            $others->selectRaw('1')
                ->from("{$table} as others")
                ->whereColumn("others.{$column}", "{$table}.{$column}")
                ->where(fn (QueryBuilder $other) => $other
                    ->whereNull('others.trackable_type')
                    ->orWhereNull('others.trackable_id')
                    ->orWhere('others.trackable_type', '!=', $this->type)
                    ->orWhere('others.trackable_id', '!=', $this->id));
        });
    }

    /**
     * Determine whether a trace or conversation has calls, and all of them are the trackable's.
     *
     * @param  'trace_id'|'conversation_id'  $column
     */
    public function owns(string $column, string $value): bool
    {
        $calls = SpectraRequest::query()->where($column, $value);

        if (! $calls->clone()->exists()) {
            return false;
        }

        return ! $calls->where(fn (Builder $other) => $other
            ->whereNull('trackable_type')
            ->orWhereNull('trackable_id')
            ->orWhere('trackable_type', '!=', $this->type)
            ->orWhere('trackable_id', '!=', $this->id))->exists();
    }
}
