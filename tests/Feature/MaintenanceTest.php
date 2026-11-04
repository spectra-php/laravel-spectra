<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spectra\Actions\Maintenance\RebuildStats;
use Spectra\Jobs\PruneEntriesJob;
use Spectra\Jobs\RebuildStatsJob;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\Tracking\RecordingSwitch;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\SpanWriter;

/**
 * The dashboard's maintenance screen: anyone who may view the dashboard sees
 * the status; pruning, rebuilding stats and pausing recording also need the
 * manageSpectra gate and the dashboard.maintenance option.
 */
beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);
});

describe('access', function () {
    it('shows the status read-only to a viewer the manageSpectra gate refuses', function () {
        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->getJson('/spectra/api/maintenance')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('can_manage', false);
    });

    it('refuses every action to a viewer the manageSpectra gate refuses', function (string $uri, array $payload) {
        Queue::fake();
        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->postJson($uri, $payload)->assertForbidden();

        Queue::assertNothingPushed();
        expect(RecordingSwitch::isPaused())->toBeFalse();
    })->with([
        'prune' => ['/spectra/api/maintenance/prune', ['hours' => 24]],
        'rebuild stats' => ['/spectra/api/maintenance/rebuild-stats', []],
        'recording' => ['/spectra/api/maintenance/recording', ['paused' => true]],
    ]);

    it('refuses every action when dashboard.maintenance is off, even to a manager', function () {
        Queue::fake();
        config(['spectra.dashboard.maintenance' => false]);

        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 24])->assertForbidden();
        $this->getJson('/spectra/api/maintenance')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('can_manage', false);

        Queue::assertNothingPushed();
    });

    it('hides the status from anyone the viewSpectra gate refuses', function () {
        Gate::define('viewSpectra', fn ($user = null) => false);

        $this->getJson('/spectra/api/maintenance')->assertForbidden();
    });

    it('allows managing in the local environment only by default', function () {
        // Resolve a fresh gate so the package defaults apply, not this file's definitions.
        app()->forgetInstance(Illuminate\Contracts\Auth\Access\Gate::class);
        Gate::clearResolvedInstance(Illuminate\Contracts\Auth\Access\Gate::class);

        expect(Gate::has('manageSpectra'))->toBeTrue()
            ->and(Gate::allows('manageSpectra'))->toBe(app()->environment('local'))
            ->and(Gate::allows('manageSpectra'))->toBeFalse();
    });
});

describe('status', function () {
    it('previews what a prune with the chosen retention would delete', function () {
        SpectraRequest::factory()->create(['created_at' => now()->subDays(10), 'media_storage_path' => ['spectra/a.png']]);
        SpectraRequest::factory()->create(['created_at' => now()->subDays(10)]);
        SpectraRequest::factory()->create(['created_at' => now()]);
        SpectraDailyStat::query()->create(['date' => now()->subDays(400)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

        $this->getJson('/spectra/api/maintenance?hours=168&stats_days=365')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('recording_paused', false)
            ->assertJsonPath('totals.requests', 3)
            ->assertJsonPath('totals.daily_stats', 1)
            ->assertJsonPath('preview.hours', 168)
            ->assertJsonPath('preview.requests', 2)
            ->assertJsonPath('preview.requests_with_media', 1)
            ->assertJsonPath('preview.daily_stats', 1);

        expect(SpectraRequest::query()->count())->toBe(3);
    });

    it('previews no daily stats when they are kept', function () {
        SpectraDailyStat::query()->create(['date' => now()->subDays(400)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

        $this->getJson('/spectra/api/maintenance?hours=24')
            ->assertJsonPath('preview.stats_days', null)
            ->assertJsonPath('preview.daily_stats', 0);
    });

    it('rejects a retention under one hour', function () {
        $this->getJson('/spectra/api/maintenance?hours=0')->assertUnprocessable();
        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 0])->assertUnprocessable();
        $this->postJson('/spectra/api/maintenance/prune', [])->assertUnprocessable();
    });
});

describe('prune', function () {
    it('queues the prune on the Spectra queue and marks it pending', function () {
        Queue::fake();
        config(['spectra.queue.connection' => 'redis', 'spectra.queue.queue' => 'spectra']);

        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 168, 'stats_days' => 365])->assertStatus(202);

        Queue::assertPushedOn('spectra', PruneEntriesJob::class, fn (PruneEntriesJob $job) => $job->hours === 168
            && $job->statsDays === 365
            && $job->connection === 'redis');
        $this->getJson('/spectra/api/maintenance')->assertJsonPath('pending.prune', true);
    });

    it('queues one prune at a time', function () {
        Queue::fake();

        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 24])->assertStatus(202);
        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 24])->assertStatus(202);

        Queue::assertPushed(PruneEntriesJob::class, 1);
    });

    it('prunes and records the run when the job runs', function () {
        $old = SpectraRequest::factory()->create(['created_at' => now()->subDays(10)]);
        $recent = SpectraRequest::factory()->create(['created_at' => now()]);

        // The sync queue runs the job inside the request.
        config(['queue.default' => 'sync']);
        $this->postJson('/spectra/api/maintenance/prune', ['hours' => 168])->assertStatus(202);

        expect(SpectraRequest::query()->pluck('id')->all())->toBe([$recent->id])
            ->and(SpectraRequest::query()->whereKey($old->id)->exists())->toBeFalse();
        $this->getJson('/spectra/api/maintenance')
            ->assertJsonPath('pending.prune', false)
            ->assertJsonPath('last_prune.requests', 1)
            ->assertJsonPath('last_prune.hours', 168);
    });

    it('stops showing a failed prune as pending', function () {
        MaintenanceHistory::markPending(MaintenanceHistory::PRUNE);

        (new PruneEntriesJob(24))->failed(new RuntimeException('database is down'));

        expect(MaintenanceHistory::isPending(MaintenanceHistory::PRUNE))->toBeFalse()
            ->and(MaintenanceHistory::last(MaintenanceHistory::PRUNE))->toBeNull();
    });

    it('records a prune run from the command too', function () {
        $this->artisan('spectra:prune', ['--hours' => 48])->assertSuccessful();

        expect(MaintenanceHistory::last(MaintenanceHistory::PRUNE))->toMatchArray(['hours' => 48, 'requests' => 0]);
    });
});

describe('rebuild stats', function () {
    it('queues the rebuild', function () {
        Queue::fake();

        $this->postJson('/spectra/api/maintenance/rebuild-stats')->assertStatus(202);

        Queue::assertPushed(RebuildStatsJob::class);
    });

    it('keeps the stats of days whose requests were pruned', function () {
        $pruned = SpectraDailyStat::query()->create([
            'date' => now()->subDays(30)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o', 'request_count' => 50,
        ]);
        // The oldest stored day was cut by a prune: its stats must survive too.
        $cut = SpectraDailyStat::query()->create([
            'date' => now()->subDays(3)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o', 'request_count' => 9,
        ]);
        SpectraRequest::factory()->create(['created_at' => now()->subDays(3), 'provider' => 'openai', 'model' => 'gpt-4o']);
        SpectraRequest::factory()->create(['created_at' => now()->subDay(), 'provider' => 'openai', 'model' => 'gpt-4o']);
        config(['queue.default' => 'sync']);

        $this->postJson('/spectra/api/maintenance/rebuild-stats')->assertStatus(202);

        expect($pruned->fresh()?->request_count)->toBe(50)
            ->and($cut->fresh()?->request_count)->toBe(9)
            ->and(SpectraDailyStat::query()->whereDate('date', now()->subDay()->toDateString())->whereNull('trackable_type')->sum('request_count'))->toEqual(1);
        $this->getJson('/spectra/api/maintenance')
            ->assertJsonPath('pending.rebuild_stats', false)
            ->assertJsonPath('last_rebuild.requests', 1)
            ->assertJsonPath('last_rebuild.from', now()->subDays(2)->toDateString());
    });

    it('rebuilds from the oldest stored day when nothing older was ever recorded', function () {
        SpectraRequest::factory()->create(['created_at' => now()->subDays(3)]);

        expect(app(RebuildStats::class)->storedDays())->toBe(1)
            ->and(MaintenanceHistory::last(MaintenanceHistory::REBUILD_STATS)['from'] ?? null)->toBe(now()->subDays(3)->toDateString());
    });

    it('deletes no stats when no request is stored', function () {
        SpectraDailyStat::query()->create(['date' => now()->subDays(30)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-4o']);

        expect(app(RebuildStats::class)->storedDays())->toBe(0)
            ->and(SpectraDailyStat::query()->count())->toBe(1);
    });
});

describe('recording', function () {
    it('pauses and resumes recording', function () {
        $this->postJson('/spectra/api/maintenance/recording', ['paused' => true])
            ->assertOk()
            ->assertJsonPath('recording_paused', true);
        expect(RecordingSwitch::isPaused())->toBeTrue();
        $this->getJson('/spectra/api/maintenance')->assertJsonPath('recording_paused', true);

        $this->postJson('/spectra/api/maintenance/recording', ['paused' => false])
            ->assertOk()
            ->assertJsonPath('recording_paused', false);
        expect(RecordingSwitch::isPaused())->toBeFalse();
    });

    it('pauses and resumes recording from the command line', function () {
        $this->artisan('spectra:pause')->assertSuccessful();
        expect(RecordingSwitch::isPaused())->toBeTrue();

        $this->artisan('spectra:resume')->assertSuccessful();
        expect(RecordingSwitch::isPaused())->toBeFalse();
    });

    it('stores no request or span while paused', function () {
        RecordingSwitch::pause();
        $context = new RequestContext(['provider' => 'openai', 'model' => 'gpt-4o']);
        $context->complete(['model' => 'gpt-4o'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

        $returned = app(RequestPersister::class)->persist($context);
        app(SpanWriter::class)->record([
            'id' => (string) Str::uuid(), 'trace_id' => (string) Str::uuid(), 'name' => 'answer',
            'started_at' => now(), 'ended_at' => now(),
        ], deferrable: false);

        expect($returned->exists)->toBeFalse()
            ->and(SpectraRequest::query()->count())->toBe(0)
            ->and(SpectraSpan::query()->count())->toBe(0);
    });

    it('stores no media while paused', function () {
        RecordingSwitch::pause();
        config()->set('spectra.storage.media.enabled', true);
        config()->set('spectra.storage.media.disk', 'spectra-test');
        Storage::fake('spectra-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-image.json'))]);

        Http::withHeaders(['x-goog-api-key' => 'test-key'])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent', [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
            ]);

        expect(SpectraRequest::query()->count())->toBe(0)
            ->and(Storage::disk('spectra-test')->allFiles())->toBe([]);
    });

    it('records again after resuming', function () {
        RecordingSwitch::pause();
        RecordingSwitch::resume();
        $context = new RequestContext(['provider' => 'openai', 'model' => 'gpt-4o']);
        $context->complete(['model' => 'gpt-4o'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

        app(RequestPersister::class)->persist($context);

        expect(SpectraRequest::query()->count())->toBe(1);
    });
});
