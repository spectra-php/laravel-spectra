<?php

declare(strict_types=1);

namespace Spectra\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spectra\Enums\DashboardSection;
use Spectra\Enums\DashboardWidget;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\Dashboards;

/**
 * What Spectra::dashboard() gives under `Spectra::fake()`: dashboards kept in
 * memory, so tests need no migrations. Arguments are checked as they are for
 * the real thing.
 */
class FakeDashboards extends Dashboards
{
    /** @var array<string, SpectraDashboard> */
    public array $dashboards = [];

    /** @var array<int, string> */
    public array $deleted = [];

    /**
     * Keep the dashboard in memory. The name and the trackable identify it, as
     * they do for the real thing.
     *
     * @param  array<int, DashboardWidget|string|array{type: DashboardWidget|string, size?: string}>  $widgets
     * @param  array<int, DashboardSection|string>  $sections
     */
    public function create(
        string $name,
        Model|string $trackable,
        int|string|null $trackableId = null,
        array $widgets = [],
        string $period = 'month',
        bool $active = true,
        array $sections = [],
        bool $showMessages = false,
    ): SpectraDashboard {
        $attributes = $this->attributes($name, $trackable, $trackableId, $widgets, $period, $active, $sections, $showMessages);

        $existing = collect($this->dashboards)->first(fn (SpectraDashboard $dashboard) => $dashboard->name === $attributes['name']
            && $dashboard->trackable_type === $attributes['trackable_type']
            && $dashboard->trackable_id === $attributes['trackable_id']);

        $dashboard = (new SpectraDashboard)->forceFill(['id' => $existing->id ?? (string) Str::uuid(), ...$attributes]);

        return $this->dashboards[$dashboard->id] = $dashboard;
    }

    /**
     * Forget the dashboard, given as the model or its id.
     */
    public function delete(SpectraDashboard|string $dashboard): bool
    {
        $id = $dashboard instanceof SpectraDashboard ? $dashboard->id : $dashboard;

        if (! array_key_exists($id, $this->dashboards)) {
            return false;
        }

        unset($this->dashboards[$id]);
        $this->deleted[] = $id;

        return true;
    }

    /**
     * Get the dashboards in memory, narrowed as the real thing narrows them.
     *
     * @return Collection<int, SpectraDashboard>
     */
    public function list(Model|string|null $trackable = null, int|string|null $trackableId = null): Collection
    {
        $dashboards = collect($this->dashboards)->values();

        if ($trackable === null) {
            return $dashboards;
        }

        [$type, $id] = $this->target($trackable, $trackableId);

        return $dashboards
            ->filter(fn (SpectraDashboard $dashboard) => $dashboard->trackable_type === $type
                && ($id === null || $dashboard->trackable_id === $id))
            ->values();
    }
}
