<?php

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Models\SpectraRequest;

it('filters requests by custom date range', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 12:00:00'));

    try {
        SpectraRequest::factory()
            ->count(2)
            ->state(new Sequence(
                ['model' => 'gpt-4o-in-range', 'model_type' => 'text', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_cost_in_cents' => 120, 'created_at' => Carbon::parse('2026-02-10 10:00:00')],
                ['model' => 'gpt-4o-out-of-range', 'model_type' => 'text', 'prompt_tokens' => 60, 'completion_tokens' => 30, 'total_cost_in_cents' => 90, 'created_at' => Carbon::parse('2026-02-12 10:00:00')],
            ))
            ->create();

        $controller = app(RequestsController::class);
        $response = app()->call([$controller, '__invoke'], ['request' => new Request([
            'period' => 'custom',
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])]);

        $json = $response->toArray();

        expect($json['total'])->toBe(1)
            ->and($json['data'])->toHaveCount(1)
            ->and($json['data'][0]['model'])->toBe('gpt-4o-in-range');
    } finally {
        Carbon::setTestNow();
    }
});
