<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Spectra\Support\Tracking\RecordingSwitch;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Pauses recording: AI calls keep working, but no request, span or media is
 * stored until spectra:resume.
 */
#[AsCommand(name: 'spectra:pause')]
class PauseCommand extends Command
{
    protected $signature = 'spectra:pause';

    protected $description = 'Pause recording AI requests until spectra:resume';

    /**
     * Pause recording.
     */
    public function handle(): int
    {
        RecordingSwitch::pause();

        $this->info('Spectra recording paused. AI calls are not stored, and their cost does not count toward budgets, until you run spectra:resume.');

        return self::SUCCESS;
    }
}
