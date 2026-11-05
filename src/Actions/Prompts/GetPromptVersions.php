<?php

declare(strict_types=1);

namespace Spectra\Actions\Prompts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Spectra\Data\PromptVersion;
use Spectra\Data\Responses\PromptsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;
use stdClass;

/**
 * The Prompts screen: every prompt template version used in the period, with
 * its calls, error rate, cost, tokens, latency and scores, newest version of
 * each template first.
 */
class GetPromptVersions
{
    /** Versions listed. */
    private const LIMIT = 100;

    /**
     * Get one row per prompt template and version, none until the prompt
     * migration has run.
     */
    public function __invoke(DateRange $dateRange): PromptsResponse
    {
        $migrated = $this->hasPromptColumns();

        return new PromptsResponse(
            versions: $migrated ? $this->versions($dateRange) : collect(),
            migrated: $migrated,
        );
    }

    /**
     * One row per prompt template and version.
     *
     * @return Collection<int, PromptVersion>
     */
    private function versions(DateRange $dateRange): Collection
    {
        return RequestAggregateQuery::make($dateRange)
            ->withPrompt()
            ->groupByColumns('prompt_name', 'prompt_version')
            ->countAs('request_count')
            ->failedCount()
            ->successfulCost()
            ->tokenSum()
            ->successfulLatency()
            ->selectRaw('MIN(created_at) as first_seen_at')
            ->selectRaw('MAX(created_at) as last_seen_at')
            ->orderBy('prompt_name')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SpectraRequest $row) => new PromptVersion(
                prompt_name: (string) $row->prompt_name,
                prompt_version: $row->prompt_version,
                request_count: $row->request_count,
                failed_count: $row->failed_count,
                error_rate: $row->request_count > 0 ? round($row->failed_count / $row->request_count, 4) : 0.0,
                total_cost_in_cents: $row->cost,
                avg_cost_in_cents: $row->request_count > 0 ? $row->cost / $row->request_count : 0.0,
                avg_tokens: $row->request_count > 0 ? (int) round($row->tokens_sum / $row->request_count) : 0,
                avg_latency_ms: round($row->avg_latency, 1),
                first_seen_at: $this->iso($row->getRawOriginal('first_seen_at')),
                last_seen_at: $this->iso($row->getRawOriginal('last_seen_at')),
                scores: $this->scores($dateRange, (string) $row->prompt_name, $row->prompt_version),
            ))
            ->values();
    }

    /**
     * The scores given to this version's calls or their traces, averaged per
     * score name. None until the app runs the scores migration.
     *
     * @return array<int, array{name: string, type: string, count: int, average: float|null}>
     */
    private function scores(DateRange $dateRange, string $name, ?string $version): array
    {
        $calls = RequestAggregateQuery::make($dateRange)->toQuery()
            ->where('prompt_name', $name)
            ->when($version === null, fn (Builder $query) => $query->whereNull('prompt_version'), fn (Builder $query) => $query->where('prompt_version', $version));

        return rescue(fn () => SpectraScore::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('request_id', $calls->clone()->select('id'))
                ->orWhereIn('trace_id', $calls->clone()->whereNotNull('trace_id')->select('trace_id')))
            ->toBase()
            ->select('name', 'type')
            ->selectRaw('COUNT(*) as score_count, AVG(value) as average')
            ->groupBy('name', 'type')
            ->orderByDesc('score_count')
            ->limit(3)
            ->get()
            ->map(fn (stdClass $score) => [
                'name' => (string) $score->name,
                'type' => (string) $score->type,
                'count' => (int) $score->score_count,
                'average' => $score->average !== null ? round((float) $score->average, 4) : null,
            ])
            ->all(), [], report: false);
    }

    /**
     * Determine if the prompt migration has run. Without it MySQL and
     * PostgreSQL reject the query, and SQLite reads the quoted column name as
     * a string and groups every call under it.
     */
    private function hasPromptColumns(): bool
    {
        return rescue(
            fn () => Schema::connection((new SpectraRequest)->getConnectionName())->hasColumns('spectra_requests', ['prompt_name', 'prompt_version']),
            false,
            report: false,
        );
    }

    /**
     * A database date as ISO 8601, as the screen reads it.
     */
    private function iso(mixed $value): ?string
    {
        return filled($value) ? Date::parse((string) $value)->toIso8601String() : null;
    }
}
