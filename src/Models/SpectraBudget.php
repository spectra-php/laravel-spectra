<?php

namespace Spectra\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $budgetable_type
 * @property string $budgetable_id
 * @property string|null $name
 * @property int|null $daily_limit
 * @property int|null $weekly_limit
 * @property int|null $monthly_limit
 * @property int|null $total_limit
 * @property int|null $daily_token_limit
 * @property int|null $weekly_token_limit
 * @property int|null $monthly_token_limit
 * @property int|null $total_token_limit
 * @property int|null $daily_request_limit
 * @property int|null $weekly_request_limit
 * @property int|null $monthly_request_limit
 * @property int $warning_threshold
 * @property int $critical_threshold
 * @property bool $hard_limit
 * @property array<int, string>|null $allowed_providers
 * @property array<int, string>|null $allowed_models
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float|null $daily_limit_in_dollars
 * @property-read float|null $weekly_limit_in_dollars
 * @property-read float|null $monthly_limit_in_dollars
 * @property-read float|null $total_limit_in_dollars
 */
class SpectraBudget extends Model
{
    use HasUuids;

    protected $table = 'spectra_budgets';

    protected $guarded = [];

    protected $attributes = [
        'warning_threshold' => 80,
        'critical_threshold' => 95,
        'hard_limit' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'weekly_limit' => 'integer',
            'monthly_limit' => 'integer',
            'total_limit' => 'integer',
            'daily_token_limit' => 'integer',
            'weekly_token_limit' => 'integer',
            'monthly_token_limit' => 'integer',
            'total_token_limit' => 'integer',
            'daily_request_limit' => 'integer',
            'weekly_request_limit' => 'integer',
            'monthly_request_limit' => 'integer',
            'warning_threshold' => 'integer',
            'critical_threshold' => 'integer',
            'hard_limit' => 'boolean',
            'allowed_providers' => 'array',
            'allowed_models' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function budgetable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function setDailyLimitAttribute(mixed $value): void
    {
        $this->attributes['daily_limit'] = $this->normalizeCents($value);
    }

    public function setWeeklyLimitAttribute(mixed $value): void
    {
        $this->attributes['weekly_limit'] = $this->normalizeCents($value);
    }

    public function setMonthlyLimitAttribute(mixed $value): void
    {
        $this->attributes['monthly_limit'] = $this->normalizeCents($value);
    }

    public function setTotalLimitAttribute(mixed $value): void
    {
        $this->attributes['total_limit'] = $this->normalizeCents($value);
    }

    public function isProviderAllowed(string $provider): bool
    {
        if (empty($this->allowed_providers)) {
            return true;
        }

        return in_array($provider, $this->allowed_providers, true);
    }

    public function isModelAllowed(string $model): bool
    {
        if (empty($this->allowed_models)) {
            return true;
        }

        return in_array($model, $this->allowed_models, true);
    }

    public function getDailyLimitInDollarsAttribute(): ?float
    {
        return $this->daily_limit ? round($this->daily_limit / 100, 4) : null;
    }

    public function getWeeklyLimitInDollarsAttribute(): ?float
    {
        return $this->weekly_limit ? round($this->weekly_limit / 100, 4) : null;
    }

    public function getMonthlyLimitInDollarsAttribute(): ?float
    {
        return $this->monthly_limit ? round($this->monthly_limit / 100, 4) : null;
    }

    public function getTotalLimitInDollarsAttribute(): ?float
    {
        return $this->total_limit ? round($this->total_limit / 100, 4) : null;
    }

    protected function normalizeCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;

        if ($numeric <= 0) {
            return 0;
        }

        return (int) ceil($numeric);
    }
}
