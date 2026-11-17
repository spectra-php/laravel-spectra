<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spectra\Support\Budget\BudgetAccess;
use Spectra\Support\Budget\TrackableBudgets;

/**
 * PUT /api/trackables/view/{id}/budget: set the trackable's budget to the
 * given limits. A limit that isn't sent is cleared; cost limits are whole
 * cents.
 */
class SaveTrackableBudgetController extends BaseApiController
{
    /**
     * The largest request limit the column holds (an unsigned integer).
     */
    protected const MAX_REQUEST_LIMIT = 4294967295;

    /**
     * Validate the limits and save them as the trackable's budget.
     */
    public function __invoke(Request $request, string $id, TrackableBudgets $budgets): JsonResponse
    {
        abort_unless(BudgetAccess::allowed(), 403);

        $input = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'limits' => ['required', 'array', 'min:1', 'max:11'],
            'limits.*' => ['required', 'array:metric,period,limit'],
            'limits.*.metric' => ['required', Rule::in(array_keys(TrackableBudgets::LIMITS))],
            'limits.*.period' => ['required', 'in:daily,weekly,monthly,total'],
            // JavaScript can't send a larger whole number exactly.
            'limits.*.limit' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'hard_limit' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $seen = [];

        foreach ($input['limits'] as $index => $limit) {
            $pair = "{$limit['metric']}.{$limit['period']}";

            if (! isset(TrackableBudgets::LIMITS[$limit['metric']][$limit['period']])) {
                throw ValidationException::withMessages(["limits.{$index}.period" => 'Requests can be limited per day, week or month, not for all time.']);
            }

            if (isset($seen[$pair])) {
                throw ValidationException::withMessages(["limits.{$index}.period" => 'Each kind of limit can be set once per period.']);
            }

            if ($limit['metric'] === 'requests' && $limit['limit'] > self::MAX_REQUEST_LIMIT) {
                throw ValidationException::withMessages(["limits.{$index}.limit" => 'A request limit can be at most '.self::MAX_REQUEST_LIMIT.'.']);
            }

            $seen[$pair] = true;
        }

        $budgets->save(
            $input['type'],
            $id,
            array_map(fn (array $limit) => ['metric' => $limit['metric'], 'period' => $limit['period'], 'limit' => (int) $limit['limit']], array_values($input['limits'])),
            $request->boolean('hard_limit'),
            $request->boolean('is_active'),
        );

        return new JsonResponse(['saved' => true]);
    }
}
