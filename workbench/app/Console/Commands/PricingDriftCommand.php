<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Pricing\DriftFinding;
use Workbench\App\Pricing\ModelsDevDrift;

/**
 * Maintainer tool: lists where the pricing catalog and models.dev disagree.
 *
 * Run with `vendor/bin/testbench spectra:pricing-drift`. Findings recorded in
 * the baseline (reviewed and kept on purpose) are not reported again unless
 * models.dev changes that price. Exits with 1 when there is anything new.
 */
#[AsCommand(name: 'spectra:pricing-drift')]
class PricingDriftCommand extends Command
{
    public const SOURCE = 'https://models.dev/api.json';

    protected $signature = 'spectra:pricing-drift
                            {--source='.self::SOURCE.' : models.dev api.json URL or local path}
                            {--baseline= : Baseline file (default: workbench/pricing-drift-baseline.json)}
                            {--update-baseline : Record every current finding in the baseline}
                            {--markdown= : Also write the report as Markdown to this path}';

    protected $description = 'Compare the pricing catalog with models.dev';

    public function handle(ModelsDevDrift $drift): int
    {
        try {
            $catalog = $this->loadCatalog($this->stringOption('source') ?? self::SOURCE);
        } catch (\Throwable $e) {
            $this->error("Could not load models.dev: {$e->getMessage()}");

            return self::FAILURE;
        }

        $findings = $drift->compare($catalog);
        $baselinePath = $this->stringOption('baseline') ?? dirname(__DIR__, 3).'/pricing-drift-baseline.json';

        if ($this->option('update-baseline')) {
            $this->writeBaseline($baselinePath, $findings);
            $this->info('Recorded '.count($findings)." findings in {$baselinePath}.");

            return self::SUCCESS;
        }

        $baseline = $this->readBaseline($baselinePath);
        $new = array_values(array_filter($findings, fn (DriftFinding $f) => ! array_key_exists($f->key(), $baseline)
            || $baseline[$f->key()] !== $f->baselineValue()));
        $stale = count(array_diff_key($baseline, array_flip(array_map(fn (DriftFinding $f) => $f->key(), $findings))));

        $this->report($new, count($findings) - count($new), $stale);

        if ($path = $this->stringOption('markdown')) {
            file_put_contents($path, $this->markdown($new));
        }

        return $new === [] ? self::SUCCESS : self::FAILURE;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    protected function loadCatalog(string $source): array
    {
        $json = str_starts_with($source, 'http')
            ? Http::timeout(30)->get($source)->throw()->body()
            : (string) file_get_contents($source);

        $catalog = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @return array<string, float|null>
     */
    protected function readBaseline(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $baseline = json_decode((string) file_get_contents($path), true);

        return is_array($baseline) ? $baseline : [];
    }

    /**
     * @param  list<DriftFinding>  $findings
     */
    protected function writeBaseline(string $path, array $findings): void
    {
        $baseline = [];

        foreach ($findings as $finding) {
            $baseline[$finding->key()] = $finding->baselineValue();
        }

        ksort($baseline);

        file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }

    /**
     * @param  list<DriftFinding>  $findings
     */
    protected function report(array $findings, int $baselined, int $stale): void
    {
        [$prices, $missing] = $this->split($findings);

        if ($prices !== []) {
            $this->newLine();
            $this->line('<options=bold>Prices that differ</> (cents per 1M tokens)');
            $this->table(['Provider', 'Model', 'Price', 'Spectra', 'models.dev'], array_map(fn (DriftFinding $f) => [
                $f->provider, $f->model, $f->field, $this->format($f->spectra), $this->format($f->modelsDev),
            ], $prices));
        }

        if ($missing !== []) {
            $this->newLine();
            $this->line('<options=bold>Priced on models.dev, not in the catalog</>');
            $this->table(['Provider', 'Model', 'Input', 'Output'], array_map(fn (DriftFinding $f) => [
                $f->provider, $f->model, $this->format($f->modelsDev), $this->format($f->modelsDevOutput),
            ], $missing));
        }

        $this->newLine();
        $this->line(sprintf('%d new findings (%d differing prices, %d missing models), %d already in the baseline.', count($findings), count($prices), count($missing), $baselined));

        if ($stale > 0) {
            $this->warn("{$stale} baseline entries no longer apply. Run with --update-baseline to drop them.");
        }

        if ($findings !== []) {
            $this->line('Check each one against the provider\'s pricing page before changing the catalog.');
        }
    }

    /**
     * @param  list<DriftFinding>  $findings
     */
    protected function markdown(array $findings): string
    {
        [$prices, $missing] = $this->split($findings);

        $lines = [
            'The weekly comparison with [models.dev]('.self::SOURCE.') found differences in the pricing catalog.',
            'models.dev is community-maintained: check each one against the provider\'s pricing page before changing a price.',
            'To keep a difference on purpose, run `vendor/bin/testbench spectra:pricing-drift --update-baseline` and commit the baseline.',
        ];

        if ($prices !== []) {
            $lines[] = '';
            $lines[] = '### Prices that differ (cents per 1M tokens)';
            $lines[] = '';
            $lines[] = '| Provider | Model | Price | Spectra | models.dev |';
            $lines[] = '| --- | --- | --- | ---: | ---: |';

            foreach ($prices as $f) {
                $lines[] = "| {$f->provider} | `{$f->model}` | {$f->field} | {$this->format($f->spectra)} | {$this->format($f->modelsDev)} |";
            }
        }

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = '### Priced on models.dev, not in the catalog';
            $lines[] = '';
            $lines[] = '| Provider | Model | Input | Output |';
            $lines[] = '| --- | --- | ---: | ---: |';

            foreach ($missing as $f) {
                $lines[] = "| {$f->provider} | `{$f->model}` | {$this->format($f->modelsDev)} | {$this->format($f->modelsDevOutput)} |";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<DriftFinding>  $findings
     * @return array{list<DriftFinding>, list<DriftFinding>}
     */
    protected function split(array $findings): array
    {
        return [
            array_values(array_filter($findings, fn (DriftFinding $f) => $f->kind === DriftFinding::PRICE)),
            array_values(array_filter($findings, fn (DriftFinding $f) => $f->kind === DriftFinding::MISSING)),
        ];
    }

    protected function format(?float $cents): string
    {
        return $cents === null ? '—' : rtrim(rtrim(number_format($cents, 4, '.', ''), '0'), '.');
    }
}
