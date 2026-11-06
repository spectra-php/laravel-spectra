<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Spectra\Support\Alerts\AlertMonitor;
use Spectra\Support\Alerts\AlertStatus;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Checks the alert rules in `spectra.alerts.rules` and notifies when one
 * starts firing, keeps firing past its cooldown, or recovers. Schedule it
 * every few minutes.
 */
#[AsCommand(name: 'spectra:check-alerts')]
class CheckAlertsCommand extends Command
{
    protected $signature = 'spectra:check-alerts';

    protected $description = 'Check the Spectra alert rules and notify when one fires or recovers';

    /**
     * Check every rule and print what each one measured.
     */
    public function handle(AlertMonitor $monitor): int
    {
        $statuses = $monitor->check(now());

        if ($statuses === []) {
            $this->components->info('No alert rules are configured in spectra.alerts.rules.');

            return self::SUCCESS;
        }

        $this->table(['Alert', 'Rule', 'Now', 'State'], collect($statuses)->map(fn (AlertStatus $status) => [
            $status->rule->name,
            $status->rule->describe(),
            $status->value !== null ? $status->rule->metric->format($status->value) : '—',
            $status->firing ? 'FIRING' : 'ok',
        ])->all());

        return self::SUCCESS;
    }
}
