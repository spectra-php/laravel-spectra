<?php

declare(strict_types=1);

namespace Spectra\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Support\Budget\BudgetEnforcer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Blocks a route with a 429 when the signed-in user's AI budget is used up
 * (`spectra.budget:openai,gpt-4o`).
 *
 * Provider and model come from the middleware arguments, else from
 * `spectra.budget.default_provider` / `default_model`, never from request
 * input, so a caller can't pick the provider or model their budget is
 * checked against. A failure while checking (database, cache) is reported
 * and the request goes through: only a budget decision may stop the route.
 */
class EnforceBudgetLimit
{
    public function __construct(
        protected BudgetEnforcer $enforcer
    ) {}

    public function handle(Request $request, Closure $next, ?string $provider = null, ?string $model = null): Response
    {
        if (! config('spectra.budget.enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        try {
            $this->enforcer->enforce(
                $user,
                filled($provider) ? $provider : (string) config('spectra.budget.default_provider'),
                filled($model) ? $model : (string) config('spectra.budget.default_model'),
            );
        } catch (BudgetExceededException $e) {
            return $this->handleBudgetExceeded($request, $e);
        } catch (Throwable $e) {
            report($e);
        }

        return $next($request);
    }

    protected function handleBudgetExceeded(Request $request, BudgetExceededException $e): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'budget_exceeded',
                'message' => $e->getMessage(),
                'limit_type' => $e->limitType,
                'limit' => $e->limit,
                'current' => $e->current,
                'percentage_used' => $e->getPercentageUsed(),
            ], 429);
        }

        abort(429, $e->getMessage());
    }
}
