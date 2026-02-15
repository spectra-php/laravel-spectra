<?php

namespace Spectra\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon $date
 * @property string $provider
 * @property string $model
 * @property string|null $model_type
 * @property string|null $trackable_type
 * @property string|null $trackable_id
 * @property int $request_count
 * @property int $successful_count
 * @property int $failed_count
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property int $total_images
 * @property int $total_videos
 * @property float $total_duration_seconds
 * @property int $total_input_characters
 * @property int $total_reasoning_tokens
 * @property float $total_cost_in_cents
 * @property int $total_latency_ms
 * @property int|null $min_latency_ms
 * @property int|null $max_latency_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float|null $avg_latency_ms
 * @property-read float $success_rate
 * @property-read float $total_cost_in_dollars
 * @property-read mixed $requests
 * @property-read mixed $successful
 * @property-read mixed $failed
 * @property-read mixed $tokens
 * @property-read mixed $cost
 * @property-read mixed $successful_requests
 * @property-read mixed $total_requests
 * @property-read mixed $failed_requests
 * @property-read mixed $total_prompt_tokens
 * @property-read mixed $total_completion_tokens
 */
class SpectraDailyStat extends Model
{
    use HasUuids;

    protected $table = 'spectra_daily_stats';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'request_count' => 'integer',
            'successful_count' => 'integer',
            'failed_count' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'total_images' => 'integer',
            'total_videos' => 'integer',
            'total_duration_seconds' => 'float',
            'total_input_characters' => 'integer',
            'total_reasoning_tokens' => 'integer',
            'total_cost_in_cents' => 'float',
            'total_latency_ms' => 'integer',
            'min_latency_ms' => 'integer',
            'max_latency_ms' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAvgLatencyMsAttribute(): ?float
    {
        if ($this->request_count === 0) {
            return null;
        }

        return round($this->total_latency_ms / $this->request_count, 2);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->request_count === 0) {
            return 0.0;
        }

        return round(($this->successful_count / $this->request_count) * 100, 2);
    }

    /**
     * Get total cost in dollars.
     * Note: total_cost_in_cents is stored in cents.
     */
    public function getTotalCostInDollarsAttribute(): float
    {
        return $this->total_cost_in_cents / 100;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDateRange(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query->whereBetween('date', [$start, $end]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeModel(Builder $query, string $model): Builder
    {
        return $query->where('model', $model);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForTrackable(Builder $query, Model $trackable): Builder
    {
        return $query->where('trackable_type', $trackable->getMorphClass())
            ->where('trackable_id', $trackable->getKey());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('trackable_type');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeModelType(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    public static function record(
        string $date,
        string $provider,
        string $model,
        ?string $trackableType,
        string|int|null $trackableId,
        bool $successful,
        int $promptTokens,
        int $completionTokens,
        float $cost,
        ?int $latencyMs,
        ?string $modelType = null,
        int $imageCount = 0,
        int $videoCount = 0,
        float $durationSeconds = 0,
        int $inputCharacters = 0,
        int $reasoningTokens = 0
    ): void {
        $trackableIdStr = $trackableId !== null ? (string) $trackableId : null;

        $stat = static::query()
            ->whereDate('date', $date)
            ->where('provider', $provider)
            ->where('model', $model)
            ->when(
                $modelType === null,
                fn ($query) => $query->whereNull('model_type'),
                fn ($query) => $query->where('model_type', $modelType)
            )
            ->when(
                $trackableType === null,
                fn ($query) => $query->whereNull('trackable_type')->whereNull('trackable_id'),
                fn ($query) => $query->where('trackable_type', $trackableType)->where('trackable_id', $trackableIdStr)
            )
            ->first();

        if ($stat === null) {
            $stat = static::create([
                'date' => $date,
                'provider' => $provider,
                'model' => $model,
                'model_type' => $modelType,
                'trackable_type' => $trackableType,
                'trackable_id' => $trackableIdStr,
                'request_count' => 0,
                'successful_count' => 0,
                'failed_count' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'total_images' => 0,
                'total_videos' => 0,
                'total_duration_seconds' => 0,
                'total_input_characters' => 0,
                'total_reasoning_tokens' => 0,
                'total_cost_in_cents' => 0,
                'total_latency_ms' => 0,
            ]);
        }

        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $reasoningTokens = max(0, $reasoningTokens);
        $imageCount = max(0, $imageCount);
        $videoCount = max(0, $videoCount);
        $durationSeconds = max(0, $durationSeconds);
        $inputCharacters = max(0, $inputCharacters);
        $cost = max(0, $cost);

        $updates = [
            'request_count' => DB::raw('request_count + 1'),
            'successful_count' => DB::raw('successful_count + '.($successful ? 1 : 0)),
            'failed_count' => DB::raw('failed_count + '.($successful ? 0 : 1)),
            'prompt_tokens' => DB::raw('prompt_tokens + '.$promptTokens),
            'completion_tokens' => DB::raw('completion_tokens + '.$completionTokens),
            'total_tokens' => DB::raw('total_tokens + '.($promptTokens + $completionTokens)),
            'total_images' => DB::raw('total_images + '.$imageCount),
            'total_videos' => DB::raw('total_videos + '.$videoCount),
            'total_duration_seconds' => DB::raw('total_duration_seconds + '.$durationSeconds),
            'total_input_characters' => DB::raw('total_input_characters + '.$inputCharacters),
            'total_reasoning_tokens' => DB::raw('total_reasoning_tokens + '.$reasoningTokens),
            'total_cost_in_cents' => DB::raw('total_cost_in_cents + '.$cost),
        ];

        if ($latencyMs !== null) {
            $latencyMs = max(0, $latencyMs);
            $updates['total_latency_ms'] = DB::raw('total_latency_ms + '.$latencyMs);
            $updates['min_latency_ms'] = DB::raw("CASE WHEN min_latency_ms IS NULL OR min_latency_ms > {$latencyMs} THEN {$latencyMs} ELSE min_latency_ms END");
            $updates['max_latency_ms'] = DB::raw("CASE WHEN max_latency_ms IS NULL OR max_latency_ms < {$latencyMs} THEN {$latencyMs} ELSE max_latency_ms END");
        }

        static::query()
            ->whereKey($stat->getKey())
            ->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAggregatedStats(
        Carbon|string $start,
        Carbon|string $end,
        ?string $provider = null,
        ?string $model = null,
        ?Model $trackable = null,
        ?string $modelType = null
    ): array {
        $query = static::query()->dateRange($start, $end);

        if ($provider) {
            $query->provider($provider);
        }

        if ($model) {
            $query->model($model);
        }

        if ($trackable) {
            $query->forTrackable($trackable);
        }

        if ($modelType !== null) {
            $query->modelType($modelType);
        }

        /** @var static $stats */
        $stats = $query->selectRaw('
            SUM(request_count) as total_requests,
            SUM(successful_count) as successful_requests,
            SUM(failed_count) as failed_requests,
            SUM(prompt_tokens) as total_prompt_tokens,
            SUM(completion_tokens) as total_completion_tokens,
            SUM(total_tokens) as total_tokens,
            SUM(total_images) as total_images,
            SUM(total_videos) as total_videos,
            SUM(total_duration_seconds) as total_duration_seconds,
            SUM(total_input_characters) as total_input_characters,
            SUM(total_cost_in_cents) as total_cost_in_cents,
            SUM(total_latency_ms) as total_latency_ms,
            MIN(min_latency_ms) as min_latency_ms,
            MAX(max_latency_ms) as max_latency_ms
        ')->first();

        $totalRequests = (int) ($stats->total_requests ?? 0);
        $successfulRequests = (int) ($stats->successful_requests ?? 0);
        $totalLatencyMs = (int) ($stats->total_latency_ms ?? 0);

        return [
            'total_requests' => $totalRequests,
            'successful_requests' => $successfulRequests,
            'failed_requests' => (int) ($stats->failed_requests ?? 0),
            'success_rate' => $totalRequests > 0
                ? round(($successfulRequests / $totalRequests) * 100, 2)
                : 0,
            'total_prompt_tokens' => (int) ($stats->total_prompt_tokens ?? 0),
            'total_completion_tokens' => (int) ($stats->total_completion_tokens ?? 0),
            'total_tokens' => (int) ($stats->total_tokens ?? 0),
            'total_images' => (int) ($stats->total_images ?? 0),
            'total_videos' => (int) ($stats->total_videos ?? 0),
            'total_duration_seconds' => (float) ($stats->total_duration_seconds ?? 0),
            'total_input_characters' => (int) ($stats->total_input_characters ?? 0),
            'total_cost_in_cents' => (float) ($stats->total_cost_in_cents ?? 0),
            'total_cost' => (float) ($stats->total_cost_in_cents ?? 0) / 100,
            'avg_latency_ms' => $totalRequests > 0
                ? round($totalLatencyMs / $totalRequests, 2)
                : null,
            'min_latency_ms' => $stats->min_latency_ms,
            'max_latency_ms' => $stats->max_latency_ms,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getDailyBreakdown(
        Carbon|string $start,
        Carbon|string $end,
        ?string $provider = null,
        ?Model $trackable = null
    ): array {
        $query = static::query()
            ->dateRange($start, $end)
            ->selectRaw('date')
            ->selectRaw('SUM(request_count) as requests')
            ->selectRaw('SUM(successful_count) as successful')
            ->selectRaw('SUM(failed_count) as failed')
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('date')
            ->orderBy('date');

        if ($provider) {
            $query->provider($provider);
        }

        if ($trackable) {
            $query->forTrackable($trackable);
        }

        return $query->get()->map(fn (self $row) => [
            'date' => $row->date->format('Y-m-d'),
            'requests' => (int) $row->requests,
            'successful' => (int) $row->successful,
            'failed' => (int) $row->failed,
            'tokens' => (int) $row->tokens,
            'cost_cents' => (float) $row->cost,
            'cost' => (float) $row->cost / 100,
        ])->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getStatsByProvider(
        Carbon|string $start,
        Carbon|string $end,
        ?Model $trackable = null
    ): array {
        $query = static::query()
            ->dateRange($start, $end)
            ->selectRaw('provider')
            ->selectRaw('SUM(request_count) as requests')
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('provider')
            ->orderByDesc('requests');

        if ($trackable) {
            $query->forTrackable($trackable);
        }

        return $query->get()->map(fn (self $row) => [
            'provider' => $row->provider,
            'requests' => (int) $row->requests,
            'tokens' => (int) $row->tokens,
            'cost_cents' => (float) $row->cost,
            'cost' => (float) $row->cost / 100,
        ])->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getStatsByModel(
        Carbon|string $start,
        Carbon|string $end,
        ?string $provider = null,
        ?Model $trackable = null
    ): array {
        $query = static::query()
            ->dateRange($start, $end)
            ->selectRaw('provider, model')
            ->selectRaw('SUM(request_count) as requests')
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->groupBy('provider', 'model')
            ->orderByDesc('requests');

        if ($provider) {
            $query->provider($provider);
        }

        if ($trackable) {
            $query->forTrackable($trackable);
        }

        return $query->get()->map(fn (self $row) => [
            'provider' => $row->provider,
            'model' => $row->model,
            'requests' => (int) $row->requests,
            'tokens' => (int) $row->tokens,
            'cost_cents' => (float) $row->cost,
            'cost' => (float) $row->cost / 100,
        ])->toArray();
    }
}
