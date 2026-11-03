<?php

declare(strict_types=1);

use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * spectra:rebuild-stats sums stored requests into daily stats for a date
 * range, and refuses a range that ends before it starts.
 */
it('rejects rebuild-stats when --from is after --to', function () {
    $this->artisan('spectra:rebuild-stats', ['--from' => '2026-06-30', '--to' => '2026-06-01'])
        ->expectsOutputToContain('must be before')
        ->assertFailed();
});

it('rebuilds stats for an explicit date range', function () {
    SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'total_cost_in_cents' => 100,
        'created_at' => now(),
    ]);

    $this->artisan('spectra:rebuild-stats', [
        '--from' => now()->subDay()->format('Y-m-d'),
        '--to' => now()->addDay()->format('Y-m-d'),
    ])->assertSuccessful();

    expect(SpectraDailyStat::query()->sum('request_count'))->toEqual(1);
});
