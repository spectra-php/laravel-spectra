<?php

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

#[AsCommand(name: 'spectra:install')]
class InstallCommand extends Command
{
    protected $signature = 'spectra:install';

    protected $description = 'Install all of the Spectra resources';

    public function handle(): int
    {
        $this->comment('Publishing Spectra Configuration...');
        $this->callSilent('vendor:publish', ['--tag' => 'spectra-config']);

        $this->comment('Publishing Spectra Migrations...');
        $this->callSilent('vendor:publish', ['--tag' => 'spectra-migrations']);

        if (confirm('Would you like to run migrations now?', true)) {
            $this->comment('Running Migrations...');
            $this->call('migrate');
        } else {
            $this->newLine();
            $this->line('Next steps:');
            $this->line('  Run <info>php artisan migrate</info> to create tables');
        }

        $this->newLine();
        $this->info('Spectra installed successfully.');

        return self::SUCCESS;
    }
}
