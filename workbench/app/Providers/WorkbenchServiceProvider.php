<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewSpectra', fn (?object $user = null) => true);

        // Demo alert rules for the dashboard's Alerts card; the seeded traffic
        // has a few rate-limited calls and slow image models.
        config()->set('spectra.alerts.rules', config('spectra.alerts.rules') ?: [
            ['name' => 'High error rate', 'metric' => 'error_rate', 'above' => 0.01, 'window' => 1440],
            ['name' => 'Slow responses', 'metric' => 'latency_p95', 'above' => 60000, 'window' => 1440],
            ['name' => 'Daily spend', 'metric' => 'cost', 'above' => 5000, 'window' => 1440],
        ]);

        // Demo review queues for the Review screen: a sample of support answers,
        // and a queue reviewers fill by hand from a trace's page.
        config()->set('spectra.annotations.queues', config('spectra.annotations.queues') ?: [
            'support-review' => [
                'description' => 'Check the support agent\'s answers',
                'scores' => [
                    'helpful' => 'boolean',
                    'accuracy' => ['type' => 'numeric', 'min' => 1, 'max' => 5],
                    'issue' => ['type' => 'categorical', 'options' => ['wrong-facts', 'tone', 'incomplete', 'none']],
                ],
                'sample' => 0.25,
                'filter' => ['prompt_name' => 'support-answer'],
            ],
            'escalations' => [
                'description' => 'Traces a teammate flagged',
                'scores' => ['needs_fix' => 'boolean'],
            ],
        ]);
    }
}
