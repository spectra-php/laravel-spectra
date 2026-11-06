# Running at High Volume

Spectra saves a row for every AI call, in your database. At a few calls a minute, that needs no thought. At thousands a minute, three questions start to matter: how long does saving a call hold up your app, how fast do the screens stay as the table grows, and how much disk does it all take? This page answers them with the setup to use and the numbers behind it.

<a name="the-short-version"></a>
## The Short Version {#the-short-version}

For a busy production app:

```shell
# Save calls off the request, on a queue outside the database
SPECTRA_QUEUE_ENABLED=true
SPECTRA_QUEUE_CONNECTION=redis
SPECTRA_QUEUE_NAME=spectra

# Save them in a database of their own
SPECTRA_DB_CONNECTION=spectra

# Keep bodies for only a share of traces
SPECTRA_PAYLOAD_SAMPLE_RATE=0.1
```

```php
// routes/console.php: keep calls for a week and their bodies for a day
Schedule::command('spectra:prune --hours=168 --payload-hours=24 --pause=10')->hourly();

// Repair yesterday's daily stats after any write that failed. Skip this if you
// prune calls within two days: a rebuild only counts the calls still stored.
Schedule::command('spectra:rebuild-stats --yesterday')->dailyAt('01:00');
```

Then run enough queue workers for your peak: `php artisan queue:work redis --queue=spectra`. Each of the sections below says what one of these does and when you can skip it.

<a name="choose-how-calls-are-saved"></a>
## Choose How Calls Are Saved {#choose-how-calls-are-saved}

Saving a call takes one transaction: the call, its bodies and its two [daily stat](/costs) rows. The [persistence mode](/configuration#persistence-modes) decides who waits for it.

| Mode | Who waits | Time added to a call (p95) | Most it saved (MySQL, PostgreSQL) |
| --- | --- | --- | --- |
| Sync (default) | Your code, inside the request | 32 ms on MySQL and 22 ms on PostgreSQL at 83 calls/s | 781 and 753 calls/s |
| After response | Nobody, but the PHP worker stays busy | under 1 ms, then 11–14 ms of worker time at 83 calls/s | 475 and 499 of the 500 calls/s offered |
| Queue on Redis | Nobody; a queue worker does it | 3 ms at 500 calls/s | 619 and 560 calls/s with 8 workers |
| Queue on the database | Nobody; a queue worker does it | 12 ms on MySQL and 4 ms on PostgreSQL at 500 calls/s | 435 and 476 calls/s with 8 workers |

Choose a queue when you can. Prefer Redis: every queued call on the database queue is one more row with its bodies in the same database.

Workers need to keep up with your peak, or calls wait in the queue. As a rule of thumb from these numbers, one worker saves about 70 calls a second, but measure it on your own database: the disk matters. These numbers come from a disk that syncs in about 0.1 ms, and a cloud disk may take 1 to 5 ms.

<a name="use-a-separate-connection"></a>
## Use a Separate Connection {#use-a-separate-connection}

[A database of its own](/installation#separate-database) keeps Spectra's load off your app's database, so a heavy report in the dashboard can't slow your checkout, and you can size and prune it on its own.

It also keeps Spectra out of your app's transactions. On a shared connection, a call tracked inside `DB::transaction()` joins it: the call is saved with your work and gone if you roll back, and its daily stats are written after your commit. That is safe, but it means a call is only as durable as your transaction. On a separate connection, a tracked call is saved whatever your transaction does.

<a name="keep-the-table-small"></a>
## Keep the Table Small {#keep-the-table-small}

At 5,000 calls a minute, Spectra saves 7.2 million rows a day. What each one takes depends mostly on its bodies:

| | MySQL | PostgreSQL |
| --- | --- | --- |
| One call, without its bodies | 1.4 KB | 0.9 KB |
| Its request and response bodies (about 7 KB of JSON) | 11 KB | 8.3 KB |
| A day at 5,000 calls/min, all bodies kept | 89 GB | 66 GB |
| A day at 5,000 calls/min, 10% of bodies kept | 18 GB | 13 GB |

<a name="sample-bodies"></a>
### Sample the Bodies {#sample-bodies}

[`storage.sampling.rate`](/configuration#sampling) keeps bodies for only that share of traces. Every call still counts toward costs, budgets, alerts and the dashboard's numbers. Failed calls keep their bodies by default.

<a name="keep-bodies-for-less-time"></a>
### Keep Bodies for Less Time {#keep-bodies-for-less-time}

Bodies are for looking into a call soon after it happened. Numbers are for the long run. So keep them apart:

```shell
php artisan spectra:prune --hours=168 --payload-hours=24
```

This keeps a week of calls and a day of bodies. A call older than that still shows its model, tokens, cost and latency; its page has no prompt or response to show.

<a name="prune-often"></a>
### Prune Often {#prune-often}

[`spectra:prune`](/commands#spectra-prune) deletes oldest first, one short statement at a time, and live writes don't wait for it, as they only touch the newest rows. In these tests it deleted 6,500 rows/s on MySQL and 29,600 on PostgreSQL while calls were being saved at 83 a second. The p95 of those saves went from 14 ms to 17 ms on MySQL, and didn't rise on PostgreSQL.

Run it hourly rather than daily, so each run stays short. If your database struggles while it runs, add `--pause=` to wait between chunks, or lower `--chunk=`. MySQL and PostgreSQL both reuse the freed space for new rows, so the files stop growing once you prune as fast as you save; they only shrink if you rebuild the table.

Keeping 50 million rows means about a week at 5,000 calls a minute. Daily stats stay for as long as you keep them, so the Overview and Costs screens go on showing the days you pruned.

<a name="what-the-screens-read"></a>
## What the Screens Read {#what-the-screens-read}

The screens add up the daily stats rather than the calls. A day of one model is one row, however many calls it took, so these screens take the same time at 50 million rows as at a million. The numbers are the same either way: counts include failures, and cost and latency cover successful calls only.

Three things can't come from the daily stats, so they read calls:

- **A filter on something the stats don't hold**, such as a tag or a finish reason.
- **A period that starts or ends mid-day.** The screens never ask for one, but your own code can.
- **Anything before [the stat key migration](/migrations#stat-key-migration) has run.**

The daily stats are only as right as what was saved. They are updated with each call, so rows you add yourself (an import, a seeder) don't count until you run [`spectra:rebuild-stats`](/commands#spectra-rebuild-stats). If a stats write fails, the error goes to your exception handler and that call is missing from the screens; scheduling a rebuild of yesterday repairs it. To read the calls themselves, set `SPECTRA_DASHBOARD_READ_FROM_ROLLUPS=false`.

<a name="what-becomes-an-estimate"></a>
## What Becomes an Estimate {#what-becomes-an-estimate}

Some answers can't be exact and fast on a large table. Each of these says so in the API's answer:

| Screen | On a large table | The field that says so |
| --- | --- | --- |
| Requests list | With a filter the stats don't hold, the total stops counting at 10,000 | `total_is_estimate` |
| Requests list | Sorted by cost, tokens or latency, only the latest `exact_row_limit` calls are sorted | `sorted_within_latest` |
| Traces, Conversations | Past `exact_row_limit` calls in the period, they list the latest ones, and the totals scale up from a sample of the latest 2,000 calls | `total_is_estimate`, `summary.estimated` |
| Performance card | Each model's percentiles cover its latest 2,000 successful calls | `sampled` |
| Errors | A group's models are counted from its latest 1,000 failures | |
| Filters | Finish reasons and efforts come from the latest 20,000 calls | |
| Maintenance | Above 100,000 rows, a table's size is the database's own estimate, and a prune preview stops counting at a million | |

Below those sizes, every number is exact. `exact_row_limit` (`SPECTRA_DASHBOARD_EXACT_ROW_LIMIT`, 50,000) sets the line.

The dashboard's screens don't show these fields yet, so a number may be an estimate without looking like one there.

For long lists, pass `next_cursor` from one answer as `cursor` in the next request. It reads the next page directly, at the same speed however deep it is, and a call saved meanwhile can't shift a row between pages.

<a name="measured"></a>
## What Was Measured {#measured}

These numbers come from MySQL 8.4 and PostgreSQL 18 in Docker on one laptop, each limited to 4 CPUs and 2 GB of buffers, with PHP on the same machine, so there was no network between them. The table held 5 million rows from 20,000 users over 30 days. Your numbers will differ; the way they change as a table grows is the part to take from this.

**Saving calls**, with about 8 KB of bodies per call:

| | MySQL | PostgreSQL |
| --- | --- | --- |
| 5,000 calls/min (83/s) on any mode | kept up | kept up |
| A burst of 500/s, sync | kept up, p95 93 ms | kept up, p95 87 ms |
| A burst of 500/s, queue on Redis | enqueued, p95 3 ms | enqueued, p95 3 ms |
| Calls lost or counted twice in any run | 0 | 0 |

**Screens** at 5 million rows, p50 with p95 in brackets. The p95 of a few runs is mostly the first one, on a cold cache:

| Screen | MySQL | PostgreSQL |
| --- | --- | --- |
| Overview | 21 ms (80 ms) | 12 ms (65 ms) |
| Requests list, first page | 56 ms (72 ms) | 19 ms (36 ms) |
| Requests list, page 10,000 | 82 ms (103 ms) | 130 ms (506 ms) |
| Requests list, by cursor 2.5M rows in | 57 ms (132 ms) | 20 ms (64 ms) |
| Traces | 160 ms (198 ms) | 116 ms (128 ms) |
| Errors | 137 ms (452 ms) | 85 ms (149 ms) |
| Performance card | 138 ms (348 ms) | 389 ms (6.4 s) |
| Conversations | 1.3 s (1.9 s) | 333 ms (388 ms) |
| Costs | 1.8 s (3.1 s) | 460 ms (686 ms) |
| Trackables | 15.6 s (20.6 s) | 1.7 s (2.9 s) |
| Prompts | 5.1 s (30 s) | 2.4 s (11.5 s) |

<a name="slow-screens"></a>
### The Slow Screens {#slow-screens}

Three screens are slower than the rest, because their work grows with what they list:

- **Trackables, and Top users on Costs**, add up every trackable's daily stats for the period, so they grow with active users times days times models. A large app should expect seconds here, more on MySQL. It would take a rollup per trackable and period, or a columnar store.
- **Prompts** adds up every call that used a prompt template. It would take daily stats per prompt version.
- **Tags** counts each tag's links, so it grows with tagged calls.

If you need these fast at this size, a read replica keeps them from slowing anything else down, and a columnar database suits this kind of question better. Spectra can send every call to an [OpenTelemetry](/opentelemetry) backend, which is one way to get them there.

<a name="tune-the-database"></a>
## Tune the Database {#tune-the-database}

The tests above show the first of these directly: the slow first runs came with a 2 GB buffer on a larger table. The others are the usual advice for a write-heavy table, not something these tests measured.

- **Give the database enough memory for the recent data and its indexes.** On MySQL, set `innodb_buffer_pool_size`; on PostgreSQL, `shared_buffers` and `effective_cache_size`.
- **On MySQL, size `innodb_redo_log_capacity` for your write rate** on a dedicated database, so busy periods don't force extra flushing.
- **On PostgreSQL, keep autovacuum ahead of pruning.** Lower `autovacuum_vacuum_scale_factor` for `spectra_requests`, or a large table waits too long between runs.
- **Mind the connections.** Each queue worker and PHP worker holds one.

<a name="upgrading-a-large-table"></a>
## Upgrading a Large Table {#upgrading-a-large-table}

None of the 4.0 migrations rewrites `spectra_requests`. Here is how long they took with 1 million requests and 558,000 daily stats:

| Migration | MySQL | PostgreSQL | Locks |
| --- | --- | --- | --- |
| [Stat key](/migrations#stat-key-migration) | 2.6 s | 0.8 s | MySQL builds its indexes online. PostgreSQL blocks writes to `spectra_daily_stats` until they are built, so pause recording or pick a quiet moment |
| [Redundant indexes](/migrations#redundant-indexes-migration) | under 1 s | under 1 s | Brief, but it waits for running queries on the table |
| [Request payloads](/migrations#request-payloads-migration) | instant | instant | None |

Then rebuild the daily stats once, which took about 6.5 minutes on MySQL and 2.5 on PostgreSQL for those 5 million requests. It does one day at a time and replaces each in its own transaction, so it can run while calls are being saved; only a call saved on a day while that day is being rebuilt can be missed, so rebuild today again afterwards. Rows from before the payloads table keep their bodies and leave with `spectra:prune`.

```shell
php artisan spectra:pause
php artisan migrate
php artisan spectra:resume
php artisan spectra:rebuild-stats
```
