<?php

declare(strict_types=1);

namespace Spectra\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

/**
 * Publishes Spectra's config and migrations and offers to migrate.
 *
 * Safe to run again after upgrading: only migrations the application does
 * not have yet are published. (`vendor:publish` renames migrations with
 * today's date, so publishing twice would copy the old ones again.)
 */
#[AsCommand(name: 'spectra:install')]
class InstallCommand extends Command
{
    protected $signature = 'spectra:install';

    protected $description = 'Install all of the Spectra resources (safe to run again after upgrading)';

    /**
     * Publish the config and new migrations, then offer to run the migrations.
     */
    public function handle(): int
    {
        $this->comment('Publishing Spectra Configuration...');
        $this->callSilent('vendor:publish', ['--tag' => 'spectra-config']);

        $this->comment('Publishing Spectra Migrations...');
        $published = $this->publishMissingMigrations();
        $this->line($published === 0
            ? '  All Spectra migrations are already published.'
            : "  Published {$published} new migration(s).");

        if ($published > 0 && confirm('Would you like to run migrations now?', true)) {
            $this->comment('Running Migrations...');
            $this->call('migrate');
        } elseif ($published > 0) {
            $this->newLine();
            $this->line('Next steps:');
            $this->line('  Run <info>php artisan migrate</info> to update the tables');
        }

        $this->newLine();
        $this->info('Spectra installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Copy each package migration whose name (without the date prefix) is not
     * in the application's migrations folder yet.
     */
    protected function publishMissingMigrations(): int
    {
        $target = database_path('migrations');
        File::ensureDirectoryExists($target);

        $existing = collect(File::files($target))
            ->map(fn ($file) => $this->withoutDatePrefix($file->getFilename()))
            ->all();

        $sources = collect(File::files(__DIR__.'/../../../database/migrations'))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->sortBy(fn ($file) => $file->getFilename());

        $stamp = now();
        $published = 0;

        foreach ($sources as $file) {
            $name = $this->withoutDatePrefix($file->getFilename());

            if (in_array($name, $existing, true)) {
                continue;
            }

            $stamp = $stamp->addSecond();
            File::copy($file->getPathname(), $target.'/'.$stamp->format('Y_m_d_His').'_'.$name);
            $published++;
        }

        return $published;
    }

    /**
     * Strip the `Y_m_d_His_` date prefix from a migration file name.
     */
    protected function withoutDatePrefix(string $filename): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
    }
}
