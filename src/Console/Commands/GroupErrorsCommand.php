<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spectra\Actions\Maintenance\GroupErrors;
use Spectra\Models\SpectraRequest;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Groups the failed calls saved before the error fingerprint migration ran,
 * so they show on the Errors screen. Run it once after upgrading; new
 * failures are grouped as they are saved.
 */
#[AsCommand(name: 'spectra:group-errors')]
class GroupErrorsCommand extends Command
{
    protected $signature = 'spectra:group-errors';

    protected $description = 'Group failed calls saved before the error fingerprint migration';

    /**
     * Fingerprint the failed calls that have none yet.
     */
    public function handle(GroupErrors $groupErrors): int
    {
        if (! Schema::connection((new SpectraRequest)->getConnectionName())->hasColumn('spectra_requests', 'error_fingerprint')) {
            $this->components->error('The error_fingerprint column does not exist yet. Run `php artisan spectra:install` first.');

            return self::FAILURE;
        }

        $count = $groupErrors();

        $this->components->info($count === 0 ? 'Every failed call is grouped already.' : "Grouped {$count} failed ".str('call')->plural($count).'.');

        return self::SUCCESS;
    }
}
