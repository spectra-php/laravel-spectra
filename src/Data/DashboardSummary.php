<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * A saved shared dashboard as an admin sees it. A null trackable id means
 * every trackable of the type; the name is null then, or when the row is gone.
 */
readonly class DashboardSummary extends DataTransferObject
{
    /**
     * Create a new shared dashboard summary instance.
     *
     * @param  list<array{type: string, size: string}>  $widgets
     * @param  list<string>  $sections
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $trackable_type,
        public ?string $trackable_id,
        public ?string $trackable_name,
        public array $widgets,
        public string $default_period,
        public bool $show_costs,
        public array $sections,
        public bool $show_messages,
        public bool $is_active,
        public string $url,
    ) {}
}
