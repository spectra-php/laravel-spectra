<?php

namespace Spectra\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Support\Budget\BudgetEnforcer;
use Symfony\Component\HttpFoundation\Response;

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

        $provider = $provider ?? $request->input('provider', config('spectra.budget.default_provider'));
        $model = $model ?? $request->input('model', config('spectra.budget.default_model'));

        try {
            $this->enforcer->enforce($user, $provider, $model);
        } catch (BudgetExceededException $e) {
            return $this->handleBudgetExceeded($request, $e);
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
