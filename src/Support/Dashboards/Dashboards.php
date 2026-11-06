<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spectra\Enums\DashboardSection;
use Spectra\Enums\DashboardWidget;
use Spectra\Models\SpectraDashboard;

/**
 * Shared dashboards from code, behind Spectra::dashboard(). It holds the same
 * rules as the dashboard's form, and throws on a wrong argument, so a typo can't
 * save a page nobody can open.
 */
class Dashboards
{
    /**
     * Create a new shared dashboards instance.
     */
    public function __construct(
        protected readonly DashboardTrackables $trackables,
    ) {}

    /**
     * Save a dashboard: a read-only page for a trackable, which logs in through
     * your app and sees only its own data. Pass a model, or a type with an id; a
     * type alone is every trackable of that type. The name and the trackable
     * identify it, so creating the same one again changes it and keeps its URL.
     *
     *   $url = Spectra::dashboard()->create('Usage', $user, widgets: ['stats', 'recent_requests'])->url();
     *   Spectra::dashboard()->create('Usage', User::class, trackableId: 123);
     *   Spectra::dashboard()->create('Usage', User::class);
     *
     * @param  array<int, DashboardWidget|string|array{type: DashboardWidget|string, size?: string}>  $widgets  Without widgets: the headline numbers, requests per day and the latest requests
     * @param  string  $period  The period it opens on: today, week, month or year
     * @param  array<int, DashboardSection|string>  $sections  The pages it links to in its header: requests, traces, conversations, errors and costs
     * @param  bool  $showMessages  Let viewers read the user's messages, the replies and error messages
     *
     * @throws InvalidArgumentException when an argument is wrong
     */
    public function create(
        string $name,
        Model|string $trackable,
        int|string|null $trackableId = null,
        array $widgets = [],
        string $period = 'month',
        bool $showCosts = false,
        bool $active = true,
        array $sections = [],
        bool $showMessages = false,
    ): SpectraDashboard {
        $attributes = $this->attributes($name, $trackable, $trackableId, $widgets, $period, $showCosts, $active, $sections, $showMessages);

        return SpectraDashboard::query()->updateOrCreate(
            ['name' => $attributes['name'], 'trackable_type' => $attributes['trackable_type'], 'trackable_id' => $attributes['trackable_id']],
            $attributes,
        );
    }

    /**
     * Delete a dashboard, given as the model or its id, which ends its URL. The
     * calls it showed stay stored. Returns whether a dashboard was deleted.
     */
    public function delete(SpectraDashboard|string $dashboard): bool
    {
        if ($dashboard instanceof SpectraDashboard) {
            return (bool) $dashboard->delete();
        }

        // An id that isn't a UUID is one PostgreSQL's uuid column would reject.
        if (! Str::isUuid($dashboard)) {
            return false;
        }

        return SpectraDashboard::query()->whereKey($dashboard)->delete() > 0;
    }

    /**
     * Get the saved dashboards, newest first. With a model, or a type and an id:
     * the ones that trackable can open, including those for every trackable of
     * its type. With a type alone: every one for that type. Paused dashboards are
     * included; check `is_active` before linking to one.
     *
     * @return Collection<int, SpectraDashboard>
     *
     * @throws InvalidArgumentException when an argument is wrong
     */
    public function list(Model|string|null $trackable = null, int|string|null $trackableId = null): Collection
    {
        $query = SpectraDashboard::query()->latest();

        if ($trackable !== null) {
            [$type, $id] = $this->target($trackable, $trackableId);

            $query->where('trackable_type', $type)->when($id !== null, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('trackable_id', $id)->orWhereNull('trackable_id'),
            ));
        }

        return $query->get()->values();
    }

    /**
     * Check the arguments and turn them into the dashboard's columns.
     *
     * @param  array<int, DashboardWidget|string|array{type: DashboardWidget|string, size?: string}>  $widgets
     * @param  array<int, DashboardSection|string>  $sections
     * @return array{name: string, trackable_type: string, trackable_id: string|null, widgets: list<array{type: string, size: string}>, default_period: string, show_costs: bool, is_active: bool, sections: list<string>, show_messages: bool}
     *
     * @throws InvalidArgumentException when an argument is wrong
     */
    protected function attributes(
        string $name,
        Model|string $trackable,
        int|string|null $trackableId,
        array $widgets,
        string $period,
        bool $showCosts,
        bool $active,
        array $sections,
        bool $showMessages,
    ): array {
        throw_if(blank($name) || mb_strlen($name) > 255, InvalidArgumentException::class, 'A dashboard needs a name of 1 to 255 characters.');
        throw_unless(in_array($period, ['today', 'week', 'month', 'year'], true), InvalidArgumentException::class, "Unknown dashboard period [{$period}]. Use today, week, month or year.");

        [$type, $id] = $this->target($trackable, $trackableId);

        throw_if(is_string($trackable) && $id !== null && $this->trackables->find($type, $id) === null, InvalidArgumentException::class, "No [{$type}] trackable has the id [{$id}].");

        return [
            'name' => $name,
            'trackable_type' => $type,
            'trackable_id' => $id,
            'widgets' => $this->widgets($widgets),
            'default_period' => $period,
            'show_costs' => $showCosts,
            'is_active' => $active,
            'sections' => $this->sections($sections),
            'show_messages' => $showMessages,
        ];
    }

    /**
     * Get the morph type and id a trackable argument names; a null id is every
     * trackable of the type.
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws InvalidArgumentException when an argument is wrong
     */
    protected function target(Model|string $trackable, int|string|null $trackableId): array
    {
        if ($trackable instanceof Model) {
            throw_if($trackableId !== null, InvalidArgumentException::class, 'Pass a trackable model or a type with an id, not both.');
            throw_if($trackable->getKey() === null, InvalidArgumentException::class, 'A dashboard can only be for a saved trackable.');

            return [$trackable->getMorphClass(), (string) $trackable->getKey()];
        }

        $class = $this->trackables->classFor($trackable);

        throw_if($class === null, InvalidArgumentException::class, "The trackable type [{$trackable}] is not an Eloquent model.");

        return [(new $class)->getMorphClass(), $trackableId === null ? null : (string) $trackableId];
    }

    /**
     * Turn the sections into their stored values.
     *
     * @param  array<int, DashboardSection|string>  $sections
     * @return list<string>
     */
    private function sections(array $sections): array
    {
        $stored = [];

        foreach ($sections as $section) {
            $case = $section instanceof DashboardSection ? $section : DashboardSection::tryFrom($section);

            throw_if($case === null, InvalidArgumentException::class, 'Unknown dashboard section ['.(is_string($section) ? $section : '').'].');

            $stored[$case->value] = $case->value;
        }

        return array_values($stored);
    }

    /**
     * Turn the widgets into their stored shape, in the order given. Without any,
     * a dashboard shows the headline numbers, requests per day and the latest requests.
     *
     * @param  array<int, DashboardWidget|string|array{type: DashboardWidget|string, size?: string}>  $widgets
     * @return list<array{type: string, size: string}>
     */
    private function widgets(array $widgets): array
    {
        if ($widgets === []) {
            $widgets = [DashboardWidget::Stats, DashboardWidget::RequestsTrend, DashboardWidget::RecentRequests];
        }

        $stored = [];

        foreach ($widgets as $widget) {
            $type = is_array($widget) ? $widget['type'] : $widget;
            $size = is_array($widget) ? ($widget['size'] ?? 'full') : 'full';
            $case = $type instanceof DashboardWidget ? $type : DashboardWidget::tryFrom($type);

            throw_if($case === null, InvalidArgumentException::class, 'Unknown dashboard widget ['.(is_string($type) ? $type : '').'].');
            throw_unless(in_array($size, ['full', 'half'], true), InvalidArgumentException::class, "Unknown dashboard widget size [{$size}]. Use full or half.");
            throw_if(array_key_exists($case->value, $stored), InvalidArgumentException::class, "The widget [{$case->value}] is on the dashboard twice.");

            $stored[$case->value] = ['type' => $case->value, 'size' => $size];
        }

        return array_values($stored);
    }
}
