<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where an AI call came from: the queued job running it, the Artisan command
 * the user ran, or the route of the HTTP request.
 *
 * Jobs and commands are reported by the queue and console events
 * (registered in the service provider). They are kept here rather than in
 * Laravel's Context because Context is copied into the jobs a request
 * dispatches, and a job's calls must name the job, not the request. Bound
 * as scoped, so queue workers and Octane start each job and request clean.
 *
 * HTTP requests are named by their route pattern (`POST /chats/{chat}`),
 * never the URL itself, so ids and query strings are not stored.
 */
class TraceOrigin
{
    /** Longest origin stored (the column is a string). */
    protected const MAX_LENGTH = 255;

    /**
     * Jobs being processed, keyed by job object: a sync job can run inside
     * another job or a request, and the innermost one names the call.
     *
     * @var array<int, string>
     */
    protected array $jobs = [];

    /** The command the user ran; commands it calls in turn don't replace it. */
    protected ?string $command = null;

    /**
     * Record that a queued job started, so its AI calls name the job.
     */
    public function startJob(object $job, string $name): void
    {
        $this->jobs[spl_object_id($job)] = $name;
    }

    /**
     * Forget a queued job once it has finished.
     */
    public function finishJob(object $job): void
    {
        unset($this->jobs[spl_object_id($job)]);
    }

    /**
     * Record the Artisan command the user ran, unless one is already running.
     */
    public function startCommand(?string $name): void
    {
        if ($this->command === null && filled($name)) {
            $this->command = "artisan {$name}";
        }
    }

    /**
     * Forget the Artisan command once it has finished.
     */
    public function finishCommand(?string $name): void
    {
        if ($this->command === "artisan {$name}") {
            $this->command = null;
        }
    }

    /**
     * Get the origin of a call made right now, or null when it can't be named.
     */
    public function current(): ?string
    {
        $origin = $this->jobs !== [] ? end($this->jobs) : null;

        $origin ??= app()->runningInConsole()
            ? $this->command
            : $this->fromRequest();

        return $origin === null ? null : Str::substr($origin, 0, self::MAX_LENGTH);
    }

    /**
     * Get the HTTP request's method and route pattern, or null before routing.
     */
    protected function fromRequest(): ?string
    {
        try {
            if (! app()->has('request')) {
                return null;
            }

            /** @var Request $request */
            $request = app('request');
            $route = $request->route();

            // Before routing, or a 404: there is no pattern, and the raw path may hold ids.
            if (! $route instanceof Route) {
                return null;
            }

            $uri = ltrim($route->uri(), '/');

            return "{$request->method()} /{$uri}";
        } catch (Throwable) {
            return null;
        }
    }
}
