<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

/**
 * The costs dashboard endpoint: overview totals, usage by model type, date
 * range filters and top spenders.
 */
it('breaks costs down by model and by model type, leaving failed calls out of the cost', function () {
    SpectraRequest::factory()
        ->count(6)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_cost_in_cents' => 100],
            ['model' => 'gpt-image-1', 'model_type' => 'image', 'image_count' => 2, 'total_cost_in_cents' => 30],
            ['model' => 'sora-2', 'model_type' => 'video', 'video_count' => 1, 'duration_seconds' => 12.5, 'total_cost_in_cents' => 60],
            ['model' => 'tts-1', 'model_type' => 'tts', 'input_characters' => 1500, 'duration_seconds' => 8.0, 'total_cost_in_cents' => 15],
            ['model' => 'whisper-1', 'model_type' => 'stt', 'duration_seconds' => 25.0, 'total_cost_in_cents' => 5],
            ['model' => 'excluded-failed-model', 'model_type' => 'text', 'prompt_tokens' => 1000, 'completion_tokens' => 1000, 'total_cost_in_cents' => 9999, 'status_code' => 500],
        ))
        ->create();

    $json = app()->call([app(CostsController::class), '__invoke'], ['request' => new Request(['period' => 'month'])])->toArray();

    $rows = collect($json['costs_by_model'])->keyBy(fn (array $row) => $row['model'].'|'.$row['model_type']);
    $typeRows = collect($json['costs_by_model_type'])->keyBy('model_type');

    expect($rows['gpt-4o|text']['tokens'])->toEqual(200)
        ->and($rows['gpt-image-1|image']['images'])->toEqual(2)
        ->and($rows['sora-2|video']['videos'])->toEqual(1)
        ->and($rows['sora-2|video']['duration_seconds'])->toEqual(12.5)
        ->and($rows['tts-1|tts']['input_characters'])->toEqual(1500)
        ->and($rows['whisper-1|stt']['duration_seconds'])->toEqual(25)
        // Counts and usage include the failed request; its cost never does.
        ->and($rows['excluded-failed-model|text']['requests'])->toBe(1)
        ->and((float) $rows['excluded-failed-model|text']['cost'])->toEqualWithDelta(0.0, 0.0001)
        ->and($typeRows['text']['requests'])->toBe(2)
        ->and($typeRows['text']['tokens'])->toEqual(2200)
        ->and((float) $typeRows['text']['cost'])->toEqualWithDelta(100.0, 0.0001)
        ->and($typeRows['image']['images'])->toEqual(2)
        ->and((float) $typeRows['image']['cost'])->toEqualWithDelta(30.0, 0.0001)
        ->and($typeRows['video']['videos'])->toEqual(1)
        ->and((float) $typeRows['video']['cost'])->toEqualWithDelta(60.0, 0.0001)
        ->and($typeRows['tts']['input_characters'])->toEqual(1500)
        ->and((float) $typeRows['tts']['cost'])->toEqualWithDelta(15.0, 0.0001)
        ->and((float) $typeRows['stt']['cost'])->toEqualWithDelta(5.0, 0.0001);
});

it('sums the cost overview cards over today, this and last week, this month and this year', function () {
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(5)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'total_cost_in_cents' => 100, 'created_at' => Date::parse('2026-02-12 09:00:00')],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'total_cost_in_cents' => 200, 'created_at' => Date::parse('2026-02-10 10:00:00')],
            ['model' => 'gpt-4o-audio', 'model_type' => 'tts', 'total_cost_in_cents' => 300, 'created_at' => Date::parse('2026-02-04 15:00:00')],
            ['model' => 'gpt-4.1', 'model_type' => 'text', 'total_cost_in_cents' => 400, 'created_at' => Date::parse('2026-01-15 13:00:00')],
            ['model' => 'excluded-error', 'model_type' => 'text', 'total_cost_in_cents' => 9000, 'status_code' => 500, 'created_at' => Date::parse('2026-02-12 11:00:00')],
        ))
        ->create();

    $json = app()->call([app(CostsController::class), '__invoke'], ['request' => new Request(['period' => 'month'])])->toArray();

    expect($json['cost_overview']['today'])->toEqualWithDelta(100, 0.0001)
        ->and($json['cost_overview']['this_week'])->toEqualWithDelta(300, 0.0001)
        ->and($json['cost_overview']['last_week'])->toEqualWithDelta(300, 0.0001)
        ->and($json['cost_overview']['this_month'])->toEqualWithDelta(600, 0.0001)
        ->and($json['cost_overview']['this_year'])->toEqualWithDelta(1000, 0.0001);
});

it('counts only the costs inside a custom date range', function () {
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'total_cost_in_cents' => 120, 'created_at' => Date::parse('2026-02-10 08:00:00')],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'total_cost_in_cents' => 80, 'created_at' => Date::parse('2026-02-12 08:00:00')],
        ))
        ->create();

    $controller = app(CostsController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
        'period' => 'custom',
        'start_date' => '2026-02-10',
        'end_date' => '2026-02-10',
    ])]);
    $json = $response->toArray();

    expect($json['total_cost_in_cents'])->toEqualWithDelta(120, 0.0001)
        ->and($json['costs_by_date'])->toHaveCount(1)
        ->and($json['costs_by_date'][0]['date'])->toBe('2026-02-10');
});

it('counts costs of every date when the period is all', function () {
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'old-model', 'model_type' => 'text', 'total_cost_in_cents' => 120, 'created_at' => Date::parse('2025-01-10 08:00:00')],
            ['model' => 'new-model', 'model_type' => 'text', 'total_cost_in_cents' => 80, 'created_at' => Date::parse('2026-02-10 08:00:00')],
        ))
        ->create();

    $controller = app(CostsController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
        'period' => 'all',
    ])]);
    $json = $response->toArray();

    expect($json['total_cost_in_cents'])->toEqualWithDelta(200, 0.0001)
        ->and($json['costs_by_date'])->toHaveCount(2);
});

it('keeps equal ids of different user models apart in the top users', function () {
    SpectraRequest::factory()->create(['total_cost_in_cents' => 10, 'trackable_type' => User::class, 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 20, 'trackable_type' => FoundationUser::class, 'trackable_id' => '1']);

    $rows = collect(app()->call([app(CostsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray()['costs_by_user']);

    expect($rows->map(fn ($row) => "{$row['trackable_type']}#{$row['user_id']}")->all())
        ->toBe([FoundationUser::class.'#1', User::class.'#1']);
});

it('ranks only users in the top users, leaving other trackables out', function () {
    Relation::morphMap(['member' => User::class, 'conversation' => SpectraRequest::class]);

    SpectraRequest::factory()->create(['total_cost_in_cents' => 10, 'trackable_type' => User::class, 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 15, 'trackable_type' => 'member', 'trackable_id' => '2']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 20, 'trackable_type' => 'conversation', 'trackable_id' => '48']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 30, 'trackable_type' => 'App\\Models\\Gone', 'trackable_id' => '3']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 40]);

    $json = app()->call([app(CostsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect(collect($json['costs_by_user'])->map(fn ($row) => "{$row['trackable_type']}#{$row['user_id']}")->all())
        ->toBe(['member#2', User::class.'#1'])
        // Only the top users are narrowed; the period's total covers every call.
        ->and((float) $json['total_cost_in_cents'])->toEqualWithDelta(115.0, 0.0001);
})->after(fn () => Relation::morphMap([], false));

it('has no top users when no call was attributed to a user', function () {
    SpectraRequest::factory()->create(['total_cost_in_cents' => 20, 'trackable_type' => 'conversation', 'trackable_id' => '48']);

    $json = app()->call([app(CostsController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect($json['costs_by_user'])->toHaveCount(0);
});
