<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A read-only page an admin designs for someone outside the dashboard: the
 * widgets it shows, and the trackable it shows them for. A null trackable id
 * means every trackable of the type, each seeing only its own numbers.
 *
 * @property string $id
 * @property string $name
 * @property string $trackable_type
 * @property string|null $trackable_id
 * @property list<array{type: string, size: string}> $widgets
 * @property string $default_period
 * @property bool $show_costs
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class SpectraDashboard extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = [
        'default_period' => 'month',
        'show_costs' => false,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'widgets' => 'json',
            'show_costs' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection') ?? parent::getConnectionName();
    }

    /**
     * Get the URL to give the people who see this dashboard.
     */
    public function url(): string
    {
        return route('spectra.dashboards.show', ['id' => $this->id]);
    }

    /**
     * Determine whether this dashboard may show the given trackable's data.
     */
    public function isFor(Model $trackable): bool
    {
        if ($trackable->getMorphClass() !== $this->trackable_type) {
            return false;
        }

        return $this->trackable_id === null || $this->trackable_id === (string) $trackable->getKey();
    }
}
