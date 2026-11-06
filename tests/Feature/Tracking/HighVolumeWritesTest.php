<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RollupSchema;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;

/**
 * Saving calls under load: one rollup row per day and model however many
 * workers create it, a call counted once however often its job runs, and a
 * host app's transaction that tracking can neither hold up nor break. The
 * tests that fork workers need a real database server.
 */
function highVolumeContext(array $attributes = []): RequestContext
{
    $context = new RequestContext([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'response_id' => 'chatcmpl-'.bin2hex(random_bytes(12)),
        ...$attributes,
    ]);
    $context->complete(['model' => 'gpt-4o'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    return $context;
}

/**
 * Run the callback in several workers at the same moment, each on its own
 * connection, and return what each one reported.
 *
 * @return array<int, mixed>
 */
function inParallelWorkers(int $workers, Closure $callback): array
{
    DB::disconnect();
    $files = [];
    $startAt = microtime(true) + 0.3;

    for ($worker = 0; $worker < $workers; $worker++) {
        $file = tempnam(sys_get_temp_dir(), 'spectra-worker');
        $files[] = $file;
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);

            try {
                file_put_contents($file, serialize($callback($worker)));
            } finally {
                // Past the test runner's own shutdown, which belongs to the parent.
                posix_kill(getmypid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return array_map(fn (string $file) => unserialize((string) file_get_contents($file)), $files);
}

function usesRealDatabaseServer(): bool
{
    return DB::connection()->getDriverName() !== 'sqlite' && function_exists('pcntl_fork');
}

describe('daily stat rows', function () {
    it('adds to one global row, whose NULL trackable a unique index would not catch', function () {
        foreach ([120, 80, 300] as $latency) {
            SpectraDailyStat::accumulate('2026-11-06', 'openai', 'gpt-4o', 'text', null, null, ['request_count' => 1, 'total_cost_in_cents' => 1.5], $latency);
        }

        $stat = SpectraDailyStat::query()->sole();

        expect($stat->request_count)->toBe(3)
            ->and($stat->total_cost_in_cents)->toEqualWithDelta(4.5, 0.000001)
            ->and($stat->min_latency_ms)->toBe(80)
            ->and($stat->max_latency_ms)->toBe(300)
            ->and($stat->stat_key)->not->toBeNull();
    });

    it('keeps a day, model type and trackable apart', function () {
        $add = fn (string $date, ?string $type, ?string $trackableType, ?string $id) => SpectraDailyStat::accumulate($date, 'openai', 'gpt-4o', $type, $trackableType, $id, ['request_count' => 1]);

        $add('2026-11-06', 'text', null, null);
        $add('2026-11-07', 'text', null, null);
        $add('2026-11-06', null, null, null);
        $add('2026-11-06', 'text', 'App\\User', '1');
        $add('2026-11-06', 'text', 'App\\User', '2');

        expect(SpectraDailyStat::query()->count())->toBe(5);
    });

    it('takes a negative amount on an existing row and starts a new row at zero', function () {
        $add = fn (array $amounts) => SpectraDailyStat::accumulate('2026-11-06', 'openai', 'gpt-4o', 'text', null, null, $amounts);

        $add(['successful_count' => -1, 'failed_count' => 1]);
        expect(SpectraDailyStat::query()->sole()->successful_count)->toBe(0);

        $add(['successful_count' => 3]);
        $add(['successful_count' => -1, 'failed_count' => 1]);

        $stat = SpectraDailyStat::query()->sole();
        expect($stat->successful_count)->toBe(2)
            ->and($stat->failed_count)->toBe(2);
    });

    it('does not touch the row for float noise', function () {
        SpectraDailyStat::accumulate('2026-11-06', 'openai', 'gpt-4o', 'text', null, null, ['request_count' => 1]);
        $statements = 0;
        DB::listen(function () use (&$statements) {
            $statements++;
        });

        SpectraDailyStat::accumulate('2026-11-06', 'openai', 'gpt-4o', 'text', null, null, ['total_cost_in_cents' => 1.3877787807814E-17]);

        expect($statements)->toBe(0);
    });

    it('still saves stats on the read-then-write path before the key migration has run', function () {
        Schema::table('spectra_daily_stats', function ($table) {
            $table->dropUnique('spectra_daily_stats_stat_key_unique');
            $table->dropColumn(['stat_key', 'latency_samples']);
        });
        app()->forgetInstance(RollupSchema::class);

        foreach ([150, 90] as $latency) {
            $context = highVolumeContext();
            $context->latencyMs = $latency;
            app(RequestPersister::class)->persist($context);
        }

        $stat = SpectraDailyStat::query()->sole();

        expect($stat->request_count)->toBe(2)
            ->and($stat->min_latency_ms)->toBe(90);
    });

    it('creates one row when every worker saves the first call of a model at once', function () {
        $results = inParallelWorkers(8, function () {
            foreach (range(1, 20) as $round) {
                SpectraDailyStat::accumulate('2026-11-06', 'openai', "new-model-{$round}", 'text', null, null, ['request_count' => 1, 'total_cost_in_cents' => 0.5], 100);
                SpectraDailyStat::accumulate('2026-11-06', 'openai', "new-model-{$round}", 'text', 'App\\User', '7', ['request_count' => 1, 'total_cost_in_cents' => 0.5], 100);
            }

            return 'done';
        });

        expect($results)->each->toBe('done')
            ->and(SpectraDailyStat::query()->count())->toBe(40)
            ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(320);
    })->skip(fn () => ! usesRealDatabaseServer(), 'Needs a database server and pcntl.');
});

describe('a retried or failing write', function () {
    it('counts a call once when its job runs again', function () {
        $attributes = app(RequestPersister::class)->buildAttributes(highVolumeContext(['trackable_type' => 'App\\User', 'trackable_id' => '1']));
        $job = new PersistSpectraRequestJob($attributes, []);

        $job->handle(app(RequestWriter::class));
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (str_contains($query->sql, 'spectra_daily_stats') && ! str_starts_with($query->sql, 'select')) {
                $writes[] = $query->sql;
            }
        });
        $job->handle(app(RequestWriter::class));

        expect(SpectraRequest::query()->count())->toBe(1)
            ->and((int) SpectraDailyStat::query()->whereNull('trackable_type')->sum('request_count'))->toBe(1)
            ->and((int) SpectraDailyStat::query()->whereNotNull('trackable_type')->sum('request_count'))->toBe(1)
            ->and($writes)->toBe([]);
    });

    it('counts a call once when its transaction deadlocks and runs again', function () {
        $failing = new class extends StatsAggregator
        {
            public int $attempts = 0;

            public function recordRequest(SpectraRequest $request): void
            {
                parent::recordRequest($request);

                throw_if(++$this->attempts === 1, QueryException::class, 'testing', 'update', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
            }
        };
        app()->instance(StatsAggregator::class, $failing);
        app()->forgetInstance(RequestWriter::class);

        app(RequestPersister::class)->persist(highVolumeContext());

        expect($failing->attempts)->toBe(2)
            ->and(SpectraRequest::query()->count())->toBe(1)
            ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(1);
    });

    it('saves the row and reports the failure when the stats keep failing', function () {
        Exceptions::fake();
        app()->instance(StatsAggregator::class, new class extends StatsAggregator
        {
            public function recordRequest(SpectraRequest $request): void
            {
                throw new RuntimeException('stats are down');
            }
        });
        app()->forgetInstance(RequestWriter::class);

        app(RequestPersister::class)->persist(highVolumeContext());

        expect(SpectraRequest::query()->count())->toBe(1);
        Exceptions::assertReported(RuntimeException::class);
    });

    it('saves the row and its stats in one transaction, so a dead worker\'s retry still counts the call', function () {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = [$query->sql, DB::transactionLevel()];
        });

        app(RequestPersister::class)->persist(highVolumeContext());

        $writes = array_filter($statements, fn (array $statement) => str_contains($statement[0], 'insert into') || str_contains($statement[0], 'update '));

        expect($writes)->not->toBeEmpty();

        foreach ($writes as [$sql, $level]) {
            expect($level)->toBe(1, $sql);
        }
    });
});

describe('inside the host app\'s transaction', function () {
    it('writes the stats after the commit, not while the transaction holds the row', function () {
        DB::transaction(function () {
            app(RequestPersister::class)->persist(highVolumeContext());

            expect(SpectraRequest::query()->count())->toBe(1)
                ->and(SpectraDailyStat::query()->count())->toBe(0);
        });

        expect((int) SpectraDailyStat::query()->sum('request_count'))->toBe(1);
    });

    it('keeps neither the row nor the stats when the app rolls back', function () {
        DB::beginTransaction();
        app(RequestPersister::class)->persist(highVolumeContext());
        DB::rollBack();

        expect(SpectraRequest::query()->count())->toBe(0)
            ->and(SpectraDailyStat::query()->count())->toBe(0);
    });

    it('lets the app\'s transaction carry on when saving the call fails', function () {
        Exceptions::fake();
        DB::table('users')->insert(['name' => 'Before', 'email' => 'a@example.test', 'password' => 'x']);

        DB::transaction(function () {
            // ip_address is 45 characters: the insert fails on MySQL and PostgreSQL.
            $context = highVolumeContext();
            $context->ipAddress = str_repeat('1', 100);
            app(RequestPersister::class)->persist($context);

            DB::table('users')->update(['name' => 'After']);
        });

        expect(DB::table('users')->value('name'))->toBe('After');
    });

    it('never rolls back the app\'s work when transactions track models in opposite orders', function () {
        DB::table('users')->insert(array_map(fn (int $id) => ['id' => $id, 'name' => 'New', 'email' => "u{$id}@example.test", 'password' => 'x'], range(1, 6)));

        $results = inParallelWorkers(6, function (int $worker) {
            $failures = 0;

            foreach (range(1, 15) as $round) {
                $models = $round % 2 === $worker % 2 ? ['gpt-4o', 'gpt-4o-mini'] : ['gpt-4o-mini', 'gpt-4o'];

                try {
                    DB::transaction(function () use ($models, $worker, $round) {
                        app(RequestPersister::class)->persist(highVolumeContext(['model' => $models[0]]));
                        Sleep::usleep(2000);
                        app(RequestPersister::class)->persist(highVolumeContext(['model' => $models[1]]));
                        DB::table('users')->where('id', $worker + 1)->update(['name' => "Round {$round}"]);
                    });
                } catch (Throwable) {
                    $failures++;
                }
            }

            return $failures;
        });

        expect(array_sum($results))->toBe(0)
            ->and(SpectraRequest::query()->count())->toBe(180)
            ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(180)
            ->and(DB::table('users')->where('name', 'Round 15')->count())->toBe(6);
    })->skip(fn () => ! usesRealDatabaseServer(), 'Needs a database server and pcntl.');
});

describe('many workers saving', function () {
    it('neither loses nor double-counts a call', function () {
        $results = inParallelWorkers(8, function (int $worker) {
            foreach (range(1, 25) as $call) {
                $context = highVolumeContext(['trackable_type' => 'App\\User', 'trackable_id' => (string) ($worker % 2), 'model' => $call % 2 === 0 ? 'gpt-4o' : 'gpt-4o-mini']);
                app(RequestPersister::class)->persist($context);
            }

            return 'done';
        });

        expect($results)->each->toBe('done')
            ->and(SpectraRequest::query()->count())->toBe(200)
            ->and((int) SpectraDailyStat::query()->whereNull('trackable_type')->sum('request_count'))->toBe(200)
            ->and((int) SpectraDailyStat::query()->whereNotNull('trackable_type')->sum('request_count'))->toBe(200)
            ->and(SpectraDailyStat::query()->whereNull('trackable_type')->count())->toBe(2);
    })->skip(fn () => ! usesRealDatabaseServer(), 'Needs a database server and pcntl.');
});
