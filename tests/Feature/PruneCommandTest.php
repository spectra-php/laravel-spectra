<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;

/**
 * spectra:prune deletes old requests and their media, keeps daily stats
 * unless asked.
 */
it('deletes old requests with their media but keeps daily stats by default', function () {
    Storage::fake();
    Storage::put('spectra/old/0.png', 'png');

    $old = SpectraRequest::factory()->create(['created_at' => now()->subDays(3), 'media_storage_path' => ['spectra/old/0.png']]);
    $recent = SpectraRequest::factory()->create(['created_at' => now()]);
    SpectraDailyStat::query()->create(['date' => now()->subDays(30)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

    $this->artisan('spectra:prune', ['--hours' => 24, '--chunk' => 1])->assertSuccessful();

    expect(SpectraRequest::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Storage::exists('spectra/old/0.png'))->toBeFalse()
        ->and(SpectraDailyStat::query()->count())->toBe(1);
});

it('prunes daily stats only when given a retention', function () {
    SpectraDailyStat::query()->create(['date' => now()->subDays(30)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);
    SpectraDailyStat::query()->create(['date' => now()->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

    $this->artisan('spectra:prune', ['--stats-days' => 7])->assertSuccessful();

    expect(SpectraDailyStat::query()->count())->toBe(1);
});

it('prunes requests when the spans table does not exist yet', function () {
    Schema::connection(config('spectra.storage.connection'))->drop('spectra_spans');
    SpectraRequest::factory()->create(['created_at' => now()->subDays(3)]);
    SpectraDailyStat::query()->create(['date' => now()->subDays(30)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

    $this->artisan('spectra:prune', ['--stats-days' => 7])->assertSuccessful();

    expect(SpectraRequest::query()->count())->toBe(0)
        ->and(SpectraDailyStat::query()->count())->toBe(0);
});

it('prunes scores older than the cutoff, and still succeeds before the scores migration runs', function () {
    SpectraScore::query()->create(['name' => 'helpful', 'type' => 'boolean', 'value' => 1, 'source' => 'user', 'trace_id' => 't1', 'created_at' => now()->subDays(3)]);
    $recent = SpectraScore::query()->create(['name' => 'helpful', 'type' => 'boolean', 'value' => 0, 'source' => 'user', 'trace_id' => 't2']);

    $this->artisan('spectra:prune', ['--hours' => 24, '--chunk' => 1])
        ->expectsOutputToContain('1 scores')
        ->assertSuccessful();

    expect(SpectraScore::query()->pluck('id')->all())->toBe([$recent->id]);

    Schema::connection(config('spectra.storage.connection'))->drop('spectra_scores');

    $this->artisan('spectra:prune')->assertSuccessful();
});
