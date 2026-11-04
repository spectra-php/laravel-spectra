<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Spectra\Support\Tracking\RecordingSwitch;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Resumes recording after spectra:pause or a pause from the dashboard.
 */
#[AsCommand(name: 'spectra:resume')]
class ResumeCommand extends Command
{
    protected $signature = 'spectra:resume';

    protected $description = 'Resume recording AI requests';

    /**
     * Resume recording.
     */
    public function handle(): int
    {
        RecordingSwitch::resume();

        $this->info('Spectra recording resumed.');

        return self::SUCCESS;
    }
}
