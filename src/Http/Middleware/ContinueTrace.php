<?php

declare(strict_types=1);

namespace Spectra\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spectra\Spectra;
use Spectra\Support\Tracking\TraceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Continues the trace and tags of the caller that sent
 * {@see Spectra::propagationHeaders()}, so the AI calls of this request, and
 * of the jobs it dispatches, join the caller's trace and carry its tags.
 *
 * Put it only on routes your own services call (an internal API, a loopback
 * MCP server): whoever reaches the route can name the trace and the tags.
 */
class ContinueTrace
{
    public function __construct(
        protected Spectra $spectra
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('spectra.enabled')) {
            return $next($request);
        }

        ['traceId' => $traceId, 'tags' => $tags] = TraceContext::fromBaggage($request->header('baggage'));

        if ($traceId !== null) {
            $this->spectra->withTraceId($traceId);
        }

        if ($tags !== []) {
            TraceContext::addTags($tags);
        }

        return $next($request);
    }
}
