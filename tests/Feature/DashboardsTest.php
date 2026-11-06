<?php

declare(strict_types=1);

/**
 * Shared dashboards: read-only pages for people outside the dashboard. Who gets
 * in, whose data they see (only the saved dashboard and the logged-in viewer
 * decide that, never the request), and what a response may never contain.
 */

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spectra\Contracts\DashboardTrackableResolver;
use Spectra\Enums\DashboardSection;
use Spectra\Enums\DashboardWidget;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraDashboard;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraSpan;
use Spectra\Support\DashboardAssets;
use Workbench\App\Models\User;

/** A trackable that isn't the logged-in user, with an id that can collide with a user's. */
class DashboardTeam extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public function getMorphClass(): string
    {
        return 'team';
    }
}

beforeEach(function () {
    config()->set('spectra.dashboards.enabled', true);

    Route::get('/login', fn () => 'login')->name('login');
});

function dashboardViewer(string $name = 'Viewer'): User
{
    return User::query()->create(['name' => $name, 'email' => fake()->unique()->safeEmail(), 'password' => 'secret']);
}

function dashboardFor(Model|string $target, array $attributes = []): SpectraDashboard
{
    return SpectraDashboard::query()->create([
        'name' => 'Usage',
        'trackable_type' => $target instanceof Model ? $target->getMorphClass() : $target,
        'trackable_id' => $target instanceof Model ? (string) $target->getKey() : null,
        'widgets' => [['type' => 'stats', 'size' => 'full']],
        ...$attributes,
    ]);
}

function dashboardCall(Model $trackable, array $attributes = []): SpectraRequest
{
    return SpectraRequest::factory()->create([
        'trackable_type' => $trackable->getMorphClass(),
        'trackable_id' => (string) $trackable->getKey(),
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'status_code' => 200,
        'error_type' => null,
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_cost_in_cents' => 123.45,
        'created_at' => now(),
        ...$attributes,
    ]);
}

function dashboardData(SpectraDashboard $dashboard, string $query = ''): TestResponse
{
    return test()->getJson("/spectra/dashboards/{$dashboard->id}/data{$query}");
}

function dashboardWidgetData(TestResponse $response, string $type): mixed
{
    return collect($response->json('widgets'))->firstWhere('type', $type)['data'] ?? null;
}

describe('who gets in', function () {
    it('shows the trackable its own dashboard', function () {
        $user = dashboardViewer();
        dashboardCall($user);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user));

        $response->assertOk();
        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(1);
    });

    it('refuses a logged-in user the dashboard is not for', function () {
        $owner = dashboardViewer();
        $other = dashboardViewer();

        $this->actingAs($other);

        dashboardData(dashboardFor($owner))->assertForbidden();
    });

    it('sends a guest to the login page', function () {
        $dashboard = dashboardFor(dashboardViewer());

        $this->get("/spectra/dashboards/{$dashboard->id}")->assertRedirect('/login');
    });

    it('answers a guest\'s data request with a 401', function () {
        dashboardData(dashboardFor(dashboardViewer()))->assertUnauthorized();
    });

    it('still refuses a guest when the app removes auth from the middleware', function () {
        $this->withoutMiddleware(Authenticate::class);

        dashboardData(dashboardFor(dashboardViewer()))->assertForbidden();
    });

    it('does not open the dashboard API to a viewer', function () {
        $user = dashboardViewer();
        dashboardFor($user);

        $this->actingAs($user);

        $this->getJson("/spectra/api/trackables/view/{$user->id}?type=".urlencode($user->getMorphClass()))->assertForbidden();
        $this->getJson('/spectra/api/requests')->assertForbidden();
    });

    it('answers 404 when shared dashboards are off', function () {
        config()->set('spectra.dashboards.enabled', false);
        $user = dashboardViewer();

        $this->actingAs($user);

        dashboardData(dashboardFor($user))->assertNotFound();
    });

    it('answers 404 for a paused dashboard', function () {
        $user = dashboardViewer();

        $this->actingAs($user);

        dashboardData(dashboardFor($user, ['is_active' => false]))->assertNotFound();
    });

    it('leaves an id that is not a uuid to the dashboard, which refuses a viewer', function () {
        $this->actingAs(dashboardViewer());

        $this->getJson('/spectra/dashboards/not-a-uuid/data')->assertForbidden();
    });

    it('opens the page for the trackable, with none of the admin bundle or its version', function () {
        $user = dashboardViewer();
        $dashboard = dashboardFor($user, ['name' => 'Acme usage']);

        $this->actingAs($user);
        $response = $this->get("/spectra/dashboards/{$dashboard->id}");

        $response->assertOk()->assertSee('Acme usage')->assertSee($dashboard->id, false)->assertDontSee('createRouter', false);
        expect(DashboardAssets::viewerScriptVariables())
            ->version->toBe('')
            ->csrfToken->toBeNull();
    });

    it('refuses the page to a logged-in user it is not for', function () {
        $dashboard = dashboardFor(dashboardViewer());

        $this->actingAs(dashboardViewer());

        $this->get("/spectra/dashboards/{$dashboard->id}")->assertForbidden();
    });
});

describe('whose data', function () {
    it('shows every viewer of an all-users dashboard only their own numbers', function () {
        $first = dashboardViewer();
        $second = dashboardViewer();
        dashboardCall($first);
        dashboardCall($second);
        dashboardCall($second);
        $dashboard = dashboardFor($first->getMorphClass());

        $this->actingAs($first);
        $firstCount = dashboardWidgetData(dashboardData($dashboard), 'stats')['total_requests'];

        $this->actingAs($second);
        $secondCount = dashboardWidgetData(dashboardData($dashboard), 'stats')['total_requests'];

        expect($firstCount)->toBe(1)->and($secondCount)->toBe(2);
    });

    it('ignores a type and id in the query string', function () {
        $viewer = dashboardViewer();
        $victim = dashboardViewer();
        dashboardCall($victim);

        $this->actingAs($viewer);
        $response = dashboardData(dashboardFor($viewer->getMorphClass()), "?type={$victim->getMorphClass()}&id={$victim->id}&trackable_id={$victim->id}");

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(0);
    });

    it('does not mix a user and a team with the same id', function () {
        $user = dashboardViewer();
        dashboardCall($user);
        dashboardCall($user, ['trackable_type' => 'team']);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user));

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(1);
    });

    it('refuses a user on a dashboard for another type with the same id', function () {
        $user = dashboardViewer();
        $team = (new DashboardTeam)->forceFill(['id' => $user->id]);

        $this->actingAs($user);

        dashboardData(dashboardFor($team))->assertForbidden();
    });

    it('matches a viewer through a morph map', function () {
        Relation::morphMap(['member' => User::class]);
        $user = dashboardViewer();
        dashboardCall($user);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor('member'));

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(1);

    })->after(fn () => Relation::$morphMap = []);

    it('lets an app resolve a team for its members', function () {
        $user = dashboardViewer();
        $team = (new DashboardTeam)->forceFill(['id' => 900, 'name' => 'Acme']);
        dashboardCall($team);

        $this->app->bind(DashboardTrackableResolver::class, fn () => new class($team) implements DashboardTrackableResolver
        {
            public function __construct(private readonly Model $team) {}

            public function resolve(Authenticatable $viewer, string $type, ?string $id): Model
            {
                return $this->team;
            }
        });

        $this->actingAs($user);
        $response = dashboardData(dashboardFor('team'));

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(1)
            ->and($response->json('trackable_name'))->toBe('Acme');
    });

    it('refuses a resolver that answers with a trackable the dashboard is not for', function () {
        $user = dashboardViewer();
        $intended = (new DashboardTeam)->forceFill(['id' => 900]);
        $wrong = (new DashboardTeam)->forceFill(['id' => 901]);

        $this->app->bind(DashboardTrackableResolver::class, fn () => new class($wrong) implements DashboardTrackableResolver
        {
            public function __construct(private readonly Model $wrong) {}

            public function resolve(Authenticatable $viewer, string $type, ?string $id): Model
            {
                return $this->wrong;
            }
        });

        $this->actingAs($user);

        dashboardData(dashboardFor($intended))->assertForbidden();
    });
});

describe('what a response may contain', function () {
    function everyWidget(): array
    {
        return collect(['stats', 'cost_trend', 'requests_trend', 'usage_by_model', 'usage_by_provider', 'recent_requests', 'budget', 'performance', 'scores'])
            ->map(fn (string $type) => ['type' => $type, 'size' => 'full'])
            ->all();
    }

    it('never sends a call\'s prompt, response, metadata or ids', function () {
        $user = dashboardViewer();
        $call = dashboardCall($user, [
            'request' => ['messages' => [['role' => 'system', 'content' => 'TOP-SECRET-PROMPT']]],
            'response' => ['choices' => [['message' => ['content' => 'TOP-SECRET-REPLY']]]],
            'metadata' => ['note' => 'TOP-SECRET-METADATA'],
            'trace_id' => 'TOP-SECRET-TRACE',
        ]);

        $this->actingAs($user);
        $body = dashboardData(dashboardFor($user, ['widgets' => everyWidget(), 'show_costs' => true]))->getContent();

        expect($body)->not->toContain('TOP-SECRET')
            ->and($body)->not->toContain($call->id);
    });

    it('sends no cost value when the dashboard hides costs', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['total_cost_in_cents' => 987.65]);
        SpectraBudget::query()->create([
            'budgetable_type' => $user->getMorphClass(),
            'budgetable_id' => (string) $user->id,
            'monthly_limit' => 54321,
            'monthly_token_limit' => 5000,
        ]);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => everyWidget()]));

        expect($response->getContent())->not->toContain('987.65')->not->toContain('54321')
            ->and(collect($response->json('widgets'))->pluck('type'))->not->toContain('cost_trend')
            ->and(dashboardWidgetData($response, 'stats')['total_cost'])->toBeNull()
            ->and(dashboardWidgetData($response, 'usage_by_model')[0]['cost'])->toBeNull()
            ->and(dashboardWidgetData($response, 'usage_by_provider')['providers'][0]['cost'])->toBeNull()
            ->and(dashboardWidgetData($response, 'stats')['cost_by_model_type'])->toBeNull()
            ->and(collect(dashboardWidgetData($response, 'budget'))->pluck('metric')->all())->toBe(['tokens']);
    });

    it('sends costs when the dashboard shows them', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['total_cost_in_cents' => 987.65]);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => everyWidget(), 'show_costs' => true]));

        expect(dashboardWidgetData($response, 'stats')['total_cost'])->toEqualWithDelta(987.65, 0.001)
            ->and(dashboardWidgetData($response, 'cost_trend')[0]['cost'])->toEqualWithDelta(987.65, 0.001);
    });

    it('sends the requests per day for the requests card', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['created_at' => now()->subDays(2)]);
        dashboardCall($user, ['created_at' => now()->subDays(2)]);
        dashboardCall($user, ['created_at' => now()]);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user), '?period=year');

        expect(dashboardWidgetData($response, 'stats')['request_trend'])->toBe([2, 1]);
    });

    it('shows performance from the trackable\'s own calls only', function () {
        $user = dashboardViewer();
        $other = dashboardViewer();
        dashboardCall($user, ['latency_ms' => 100]);
        dashboardCall($user, ['status_code' => 500, 'latency_ms' => 100]);
        dashboardCall($other, ['latency_ms' => 90000]);
        dashboardCall($other, ['model' => 'theirs-only', 'latency_ms' => 90000]);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'performance', 'size' => 'full']]]));
        $rows = dashboardWidgetData($response, 'performance');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['model'])->toBe('gpt-4o')
            ->and($rows[0]['request_count'])->toBe(2)
            ->and($rows[0]['failed_count'])->toBe(1)
            ->and($rows[0]['latency_p99'])->toBe(100);
    });

    it('shows scores on the trackable\'s own calls, traces and conversations only', function () {
        $user = dashboardViewer();
        $other = dashboardViewer();
        $call = dashboardCall($user, ['trace_id' => 'trace-mine', 'conversation_id' => 'chat-mine']);
        $theirs = dashboardCall($other, ['trace_id' => 'trace-theirs', 'conversation_id' => 'chat-theirs']);
        $score = fn (array $attributes) => SpectraScore::query()->create(['type' => 'numeric', 'value' => 1, 'source' => 'api', 'comment' => 'TOP-SECRET-COMMENT', ...$attributes]);
        $score(['name' => 'on_my_call', 'request_id' => $call->id]);
        $score(['name' => 'on_my_trace', 'trace_id' => 'trace-mine']);
        $score(['name' => 'on_my_chat', 'conversation_id' => 'chat-mine']);
        $score(['name' => 'on_their_call', 'request_id' => $theirs->id]);
        $score(['name' => 'on_their_trace', 'trace_id' => 'trace-theirs']);
        $score(['name' => 'on_nothing']);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'scores', 'size' => 'full']]]));

        expect(collect(dashboardWidgetData($response, 'scores'))->pluck('name')->sort()->values()->all())->toBe(['on_my_call', 'on_my_chat', 'on_my_trace'])
            ->and($response->getContent())->not->toContain('TOP-SECRET');
    });

    it('breaks the trackable\'s requests down by provider and by model type', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['model_type' => 'text']);
        dashboardCall($user, ['model_type' => 'text']);
        dashboardCall($user, ['model_type' => 'image', 'provider' => 'google']);
        dashboardCall(dashboardViewer(), ['model_type' => 'video', 'provider' => 'xai']);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'usage_by_provider', 'size' => 'half']]]));
        $breakdown = dashboardWidgetData($response, 'usage_by_provider');

        expect(collect($breakdown['providers'])->pluck('provider')->sort()->values()->all())->toBe(['google', 'openai'])
            ->and(collect($breakdown['model_types'])->pluck('count', 'model_type')->all())->toEqual(['image' => 1, 'text' => 2]);
    });

    it('lists the latest ten calls and the five most used models, as the Overview does', function () {
        $user = dashboardViewer();
        foreach (range(1, 12) as $index) {
            dashboardCall($user, ['model' => 'model-'.($index % 7)]);
        }

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'recent_requests', 'size' => 'half'], ['type' => 'usage_by_model', 'size' => 'half']]]));

        expect(dashboardWidgetData($response, 'recent_requests'))->toHaveCount(10)
            ->and(dashboardWidgetData($response, 'usage_by_model'))->toHaveCount(5);
    });

    it('queries only the widgets on the dashboard, in the saved order', function () {
        $user = dashboardViewer();
        dashboardCall($user);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [
            ['type' => 'recent_requests', 'size' => 'half'],
            ['type' => 'stats', 'size' => 'full'],
        ]]));

        expect(collect($response->json('widgets'))->pluck('type')->all())->toBe(['recent_requests', 'stats'])
            ->and($response->json('widgets.0.size'))->toBe('half');
    });

    it('skips a widget type it does not know', function () {
        $user = dashboardViewer();

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'removed_widget', 'size' => 'full']]]));

        $response->assertOk();
        expect($response->json('widgets'))->toBe([]);
    });

    it('marks a 2xx call with an error as failed in the recent requests', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['status_code' => 200, 'error_type' => 'stream_interrupted']);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user, ['widgets' => [['type' => 'recent_requests', 'size' => 'full']]]));

        expect(dashboardWidgetData($response, 'recent_requests')[0]['failed'])->toBeTrue();
    });
});

describe('the period', function () {
    it('starts on the dashboard\'s default and switches to a preset', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['created_at' => now()->subDays(40)]);
        $dashboard = dashboardFor($user, ['default_period' => 'today']);

        $this->actingAs($user);
        $default = dashboardData($dashboard);
        $year = dashboardData($dashboard, '?period=year');

        expect($default->json('period'))->toBe('today')
            ->and(dashboardWidgetData($default, 'stats')['total_requests'])->toBe(0)
            ->and($year->json('period'))->toBe('year');
    });

    it('rejects all time', function () {
        $user = dashboardViewer();

        $this->actingAs($user);

        dashboardData(dashboardFor($user), '?period=all')->assertUnprocessable();
    });

    it('ignores custom dates', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['created_at' => now()->subYears(3)]);

        $this->actingAs($user);
        $response = dashboardData(dashboardFor($user), '?start_date=2000-01-01&end_date=2100-01-01');

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(0);
    });
});

describe('designing them', function () {
    function dashboardInput(array $input = []): array
    {
        return [
            'name' => 'Usage',
            'trackable_type' => (new User)->getMorphClass(),
            'trackable_id' => null,
            'widgets' => [['type' => 'stats', 'size' => 'full']],
            'default_period' => 'month',
            'show_costs' => false,
            'is_active' => true,
            ...$input,
        ];
    }

    beforeEach(function () {
        Gate::define('viewSpectra', fn ($user = null) => true);
        Gate::define('manageSpectra', fn ($user = null) => true);
    });

    it('saves, lists, changes and deletes a dashboard', function () {
        $id = $this->postJson('/spectra/api/dashboards', dashboardInput())->assertCreated()->json('id');

        $listed = $this->getJson('/spectra/api/dashboards')->assertOk();
        expect($listed->json('dashboards.0.id'))->toBe($id)
            ->and($listed->json('dashboards.0.url'))->toEndWith("/spectra/dashboards/{$id}");

        $this->putJson("/spectra/api/dashboards/{$id}", dashboardInput(['name' => 'Renamed']))->assertOk();
        expect(SpectraDashboard::query()->findOrFail($id)->name)->toBe('Renamed');

        $this->deleteJson("/spectra/api/dashboards/{$id}")->assertOk();
        expect(SpectraDashboard::query()->count())->toBe(0);
    });

    it('refuses someone with only viewSpectra', function () {
        Gate::define('manageSpectra', fn ($user = null) => false);
        $dashboard = dashboardFor((new User)->getMorphClass());

        $listed = $this->getJson('/spectra/api/dashboards')->assertOk();

        expect($listed->json('allowed'))->toBeFalse()->and($listed->json('dashboards'))->toBe([]);
        $this->postJson('/spectra/api/dashboards', dashboardInput())->assertForbidden();
        $this->putJson("/spectra/api/dashboards/{$dashboard->id}", dashboardInput())->assertForbidden();
        $this->deleteJson("/spectra/api/dashboards/{$dashboard->id}")->assertForbidden();
        $this->getJson("/spectra/api/dashboards/{$dashboard->id}/preview?trackable_id=1")->assertForbidden();
    });

    it('says when the migration has not run, instead of failing', function () {
        Schema::drop('spectra_dashboards');

        $listed = $this->getJson('/spectra/api/dashboards')->assertOk();

        expect($listed->json('migrated'))->toBeFalse()->and($listed->json('dashboards'))->toBe([]);
    });

    it('refuses writes when shared dashboards are off', function () {
        config()->set('spectra.dashboards.enabled', false);

        $this->postJson('/spectra/api/dashboards', dashboardInput())->assertForbidden();
    });

    it('rejects a type that is not an Eloquent model', function () {
        $this->postJson('/spectra/api/dashboards', dashboardInput(['trackable_type' => 'stdClass']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trackable_type');
    });

    it('rejects an id with no trackable behind it', function () {
        $this->postJson('/spectra/api/dashboards', dashboardInput(['trackable_id' => '99999']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trackable_id');
    });

    it('rejects an unknown widget, a repeated widget and an unknown size', function (array $widgets) {
        $this->postJson('/spectra/api/dashboards', dashboardInput(['widgets' => $widgets]))->assertUnprocessable();
    })->with([
        'unknown widget' => [[['type' => 'raw_prompts', 'size' => 'full']]],
        'repeated widget' => [[['type' => 'stats', 'size' => 'full'], ['type' => 'stats', 'size' => 'half']]],
        'unknown size' => [[['type' => 'stats', 'size' => 'huge']]],
    ]);

    it('previews an all-users dashboard for the id the admin gives', function () {
        $user = dashboardViewer();
        dashboardCall($user);
        $dashboard = dashboardFor($user->getMorphClass());

        $response = $this->getJson("/spectra/api/dashboards/{$dashboard->id}/preview?trackable_id={$user->id}")->assertOk();

        expect(dashboardWidgetData($response, 'stats')['total_requests'])->toBe(1);
        $this->getJson("/spectra/api/dashboards/{$dashboard->id}/preview")->assertUnprocessable();
        $this->getJson("/spectra/api/dashboards/{$dashboard->id}/preview?trackable_id=99999")->assertNotFound();
    });
});

describe('from code', function () {
    it('creates a dashboard for a model, and gives its URL', function () {
        $user = dashboardViewer();

        $dashboard = Spectra::dashboard()->create('Usage', $user, widgets: ['stats', ['type' => DashboardWidget::RecentRequests, 'size' => 'half']], showCosts: true);

        expect($dashboard->trackable_type)->toBe($user->getMorphClass())
            ->and($dashboard->trackable_id)->toBe((string) $user->id)
            ->and($dashboard->widgets)->toBe([['type' => 'stats', 'size' => 'full'], ['type' => 'recent_requests', 'size' => 'half']])
            ->and($dashboard->show_costs)->toBeTrue()
            ->and($dashboard->url())->toEndWith("/spectra/dashboards/{$dashboard->id}");
    });

    it('creates a dashboard from a type and an id', function () {
        $user = dashboardViewer();

        $dashboard = Spectra::dashboard()->create('Usage', User::class, trackableId: $user->id);

        expect($dashboard->trackable_type)->toBe($user->getMorphClass())
            ->and($dashboard->trackable_id)->toBe((string) $user->id);
    });

    it('creates a dashboard for every trackable of a type, which each viewer can open', function () {
        $user = dashboardViewer();
        dashboardCall($user);

        $dashboard = Spectra::dashboard()->create('Usage', User::class);

        $this->actingAs($user);

        expect($dashboard->trackable_id)->toBeNull()
            ->and(dashboardWidgetData(dashboardData($dashboard), 'stats')['total_requests'])->toBe(1);
    });

    it('stores the morph alias when given the class', function () {
        Relation::morphMap(['member' => User::class]);

        $dashboard = Spectra::dashboard()->create('Usage', User::class);

        expect($dashboard->trackable_type)->toBe('member');
    })->after(fn () => Relation::$morphMap = []);

    it('starts on the default widgets and hides costs', function () {
        $dashboard = Spectra::dashboard()->create('Usage', User::class);

        expect(collect($dashboard->widgets)->pluck('type')->all())->toBe(['stats', 'requests_trend', 'recent_requests'])
            ->and($dashboard->show_costs)->toBeFalse();
    });

    it('changes the same dashboard when created again, keeping its URL', function () {
        $user = dashboardViewer();

        $first = Spectra::dashboard()->create('Usage', $user);
        $second = Spectra::dashboard()->create('Usage', $user, widgets: ['budget'], period: 'week');

        expect($second->id)->toBe($first->id)
            ->and(SpectraDashboard::query()->count())->toBe(1)
            ->and($second->fresh()->default_period)->toBe('week');
    });

    it('keeps dashboards apart by name and by trackable', function () {
        Spectra::dashboard()->create('Usage', dashboardViewer());
        Spectra::dashboard()->create('Usage', dashboardViewer());
        Spectra::dashboard()->create('Usage', User::class);
        Spectra::dashboard()->create('Costs', User::class);

        expect(SpectraDashboard::query()->count())->toBe(4);
    });

    it('rejects wrong arguments', function (Closure $call, string $message) {
        expect(fn () => $call(dashboardViewer()))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'empty name' => [fn (User $user) => Spectra::dashboard()->create(' ', $user), 'needs a name'],
        'model and id' => [fn (User $user) => Spectra::dashboard()->create('Usage', $user, trackableId: 1), 'not both'],
        'unsaved model' => [fn () => Spectra::dashboard()->create('Usage', new User), 'saved trackable'],
        'not a model' => [fn () => Spectra::dashboard()->create('Usage', 'stdClass'), 'not an Eloquent model'],
        'missing id' => [fn () => Spectra::dashboard()->create('Usage', User::class, trackableId: 99999), 'has the id'],
        'unknown widget' => [fn (User $user) => Spectra::dashboard()->create('Usage', $user, widgets: ['raw_prompts']), 'Unknown dashboard widget'],
        'unknown size' => [fn (User $user) => Spectra::dashboard()->create('Usage', $user, widgets: [['type' => 'stats', 'size' => 'huge']]), 'widget size'],
        'repeated widget' => [fn (User $user) => Spectra::dashboard()->create('Usage', $user, widgets: ['stats', 'stats']), 'twice'],
        'unknown period' => [fn (User $user) => Spectra::dashboard()->create('Usage', $user, period: 'all'), 'period'],
    ]);

    it('deletes a dashboard by model and by id', function () {
        $first = Spectra::dashboard()->create('First', User::class);
        $second = Spectra::dashboard()->create('Second', User::class);

        expect(Spectra::dashboard()->delete($first))->toBeTrue()
            ->and(Spectra::dashboard()->delete($second->id))->toBeTrue()
            ->and(SpectraDashboard::query()->count())->toBe(0);
    });

    it('answers false when there is nothing to delete', function (string $id) {
        expect(Spectra::dashboard()->delete($id))->toBeFalse();
    })->with(['unknown uuid' => '01a1b04c-d820-7140-bea1-3e85e8d94cfb', 'not a uuid' => 'not-a-uuid']);

    it('lists every dashboard, newest first', function () {
        $older = Spectra::dashboard()->create('Older', User::class);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        Spectra::dashboard()->create('Newer', User::class);

        expect(Spectra::dashboard()->list()->pluck('name')->all())->toBe(['Newer', 'Older']);
    });

    it('lists what a trackable can open, including those for its whole type', function () {
        $user = dashboardViewer();
        Spectra::dashboard()->create('Theirs', $user);
        Spectra::dashboard()->create('Everyone', User::class);
        Spectra::dashboard()->create('Someone else', dashboardViewer());

        $byModel = Spectra::dashboard()->list($user)->pluck('name')->sort()->values()->all();
        $byId = Spectra::dashboard()->list(User::class, $user->id)->pluck('name')->sort()->values()->all();

        expect($byModel)->toBe(['Everyone', 'Theirs'])->and($byId)->toBe($byModel);
    });

    it('lists every dashboard for a type', function () {
        Spectra::dashboard()->create('Theirs', dashboardViewer());
        Spectra::dashboard()->create('Everyone', User::class);
        SpectraDashboard::query()->create(['name' => 'Teams', 'trackable_type' => 'team', 'widgets' => []]);

        expect(Spectra::dashboard()->list(User::class))->toHaveCount(2);
    });

    it('keeps dashboards in memory on the fake', function () {
        $fake = Spectra::fake();

        $dashboard = Spectra::dashboard()->create('Usage', User::class);

        $fake->assertDashboardCreated('Usage');
        expect($dashboard->url())->toContain('/spectra/dashboards/')
            ->and(Spectra::dashboard()->list(User::class))->toHaveCount(1)
            ->and(SpectraDashboard::query()->count())->toBe(0)
            ->and(Spectra::dashboard()->delete($dashboard))->toBeTrue();
        $fake->assertDashboardDeleted($dashboard->id);
    });

    it('checks arguments on the fake too', function () {
        Spectra::fake();

        expect(fn () => Spectra::dashboard()->create('Usage', User::class, widgets: ['raw_prompts']))->toThrow(InvalidArgumentException::class);
    });
});

describe('the pages in the header', function () {
    function dashboardWithSections(Model|string $target, array $attributes = []): SpectraDashboard
    {
        return dashboardFor($target, ['sections' => ['requests', 'traces', 'conversations', 'errors', 'costs'], 'show_costs' => true, ...$attributes]);
    }

    function dashboardSection(SpectraDashboard $dashboard, string $path): TestResponse
    {
        return test()->getJson("/spectra/dashboards/{$dashboard->id}/data/{$path}");
    }

    it('answers 404 for a page the admin did not turn on', function (string $path) {
        $user = dashboardViewer();

        $this->actingAs($user);

        dashboardSection(dashboardFor($user), $path)->assertNotFound();
        $this->get('/spectra/dashboards/'.dashboardFor($user, ['name' => 'Page'])->id.'/'.explode('/', $path)[0])->assertNotFound();
    })->with(['requests', 'traces', 'conversations', 'errors', 'costs']);

    it('refuses every page to a logged-in user it is not for', function (string $path) {
        $dashboard = dashboardWithSections(dashboardViewer());

        $this->actingAs(dashboardViewer());

        dashboardSection($dashboard, $path)->assertForbidden();
    })->with(['requests', 'traces', 'conversations', 'errors', 'costs', 'traces/some-trace', 'conversations/some-chat', 'requests/01a1b04c-d820-7140-bea1-3e85e8d94cfb']);

    it('answers a guest with a 401 on every page', function (string $path) {
        dashboardSection(dashboardWithSections(dashboardViewer()), $path)->assertUnauthorized();
    })->with(['requests', 'traces', 'conversations', 'errors', 'costs']);

    it('opens a page directly, for a reload', function () {
        $user = dashboardViewer();
        $dashboard = dashboardWithSections($user);

        $this->actingAs($user);

        $this->get("/spectra/dashboards/{$dashboard->id}/traces/some-trace")->assertOk()->assertSee('window.SpectraViewer', false);
    });

    it('hides the Costs page on a dashboard that hides costs', function () {
        $user = dashboardViewer();
        $dashboard = dashboardWithSections($user, ['show_costs' => false]);

        $this->actingAs($user);

        dashboardSection($dashboard, 'costs')->assertNotFound();
        expect($dashboard->enabledSections())->not->toContain('costs');
    });

    it('lists only the viewer\'s calls, with no id or text when messages are off', function () {
        $user = dashboardViewer();
        $mine = dashboardCall($user, ['request' => ['messages' => [['role' => 'user', 'content' => 'TOP-SECRET']]]]);
        dashboardCall(dashboardViewer());
        dashboardCall($user, ['trackable_type' => 'team']);

        $this->actingAs($user);
        $response = dashboardSection(dashboardWithSections($user), 'requests')->assertOk();

        expect($response->json('total'))->toBe(1)
            ->and($response->getContent())->not->toContain('TOP-SECRET')->not->toContain($mine->id);
    });

    it('never opens a call when messages are off', function () {
        $user = dashboardViewer();
        $call = dashboardCall($user);

        $this->actingAs($user);

        dashboardSection(dashboardWithSections($user), "requests/{$call->id}")->assertNotFound();
    });

    it('opens the viewer\'s call with only the user\'s message and the reply', function () {
        $user = dashboardViewer();
        $call = dashboardCall($user, [
            'request' => ['messages' => [['role' => 'system', 'content' => 'TOP-SECRET-SYSTEM'], ['role' => 'user', 'content' => 'Where is my order?']], 'tools' => [['name' => 'TOP-SECRET-TOOL']]],
            'response' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'On its way.']]]],
        ]);

        $this->actingAs($user);
        $response = dashboardSection(dashboardWithSections($user, ['show_messages' => true]), "requests/{$call->id}")->assertOk();

        expect($response->json('prompt'))->toBe('Where is my order?')
            ->and($response->getContent())->not->toContain('TOP-SECRET');
    });

    it('never opens another user\'s call, even with messages on', function () {
        $user = dashboardViewer();
        $theirs = dashboardCall(dashboardViewer());

        $this->actingAs($user);

        dashboardSection(dashboardWithSections($user, ['show_messages' => true]), "requests/{$theirs->id}")->assertNotFound();
    });

    it('lists a trace only when every call in it is the viewer\'s', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['trace_id' => 'all-mine']);
        dashboardCall($user, ['trace_id' => 'all-mine']);
        dashboardCall($user, ['trace_id' => 'shared']);
        dashboardCall(dashboardViewer(), ['trace_id' => 'shared']);
        dashboardCall($user, ['trace_id' => 'half-unattributed']);
        dashboardCall($user, ['trace_id' => 'half-unattributed', 'trackable_type' => null, 'trackable_id' => null]);
        dashboardCall(dashboardViewer(), ['trace_id' => 'theirs']);

        $this->actingAs($user);
        $dashboard = dashboardWithSections($user);
        $listed = dashboardSection($dashboard, 'traces')->assertOk();

        expect(collect($listed->json('data'))->pluck('trace_id')->all())->toBe(['all-mine'])
            ->and($listed->json('data.0.request_count'))->toBe(2);

        dashboardSection($dashboard, 'traces/all-mine')->assertOk();
        dashboardSection($dashboard, 'traces/shared')->assertNotFound();
        dashboardSection($dashboard, 'traces/half-unattributed')->assertNotFound();
        dashboardSection($dashboard, 'traces/theirs')->assertNotFound();
        dashboardSection($dashboard, 'traces/does-not-exist')->assertNotFound();
    });

    it('sends a trace without origins, route names, tool arguments or results', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['trace_id' => 'mine', 'origin' => 'POST /TOP-SECRET-ROUTE', 'endpoint' => '/TOP-SECRET-ENDPOINT']);
        foreach ([['request', 'POST /TOP-SECRET-ROUTE'], ['tool', 'lookup_order']] as $index => [$kind, $name]) {
            SpectraSpan::query()->create([
                'trace_id' => 'mine', 'span_id' => "span{$index}", 'name' => $name, 'kind' => $kind, 'origin' => 'TOP-SECRET-ORIGIN',
                'started_at' => now(), 'ended_at' => now(), 'duration_ms' => 5, 'status' => 'error', 'error_message' => 'TOP-SECRET-ERROR',
                'attributes' => ['gen_ai.tool.call.arguments' => 'TOP-SECRET-ARGUMENTS'],
            ]);
        }

        $this->actingAs($user);
        $response = dashboardSection(dashboardWithSections($user), 'traces/mine')->assertOk();

        expect($response->getContent())->not->toContain('TOP-SECRET')
            ->and(collect($response->json('spans'))->pluck('name')->all())->toContain('Request', 'lookup_order');
    });

    it('sends no cost in traces or conversations when the dashboard hides costs', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['trace_id' => 'mine', 'conversation_id' => 'chat', 'total_cost_in_cents' => 987.65]);

        $this->actingAs($user);
        $dashboard = dashboardWithSections($user, ['show_costs' => false]);

        foreach (['traces', 'traces/mine', 'conversations', 'conversations/chat'] as $path) {
            expect(dashboardSection($dashboard, $path)->assertOk()->getContent())->not->toContain('987.65');
        }
    });

    it('lists and opens a conversation only when every call in it is the viewer\'s', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['conversation_id' => 'chat/with-slash', 'conversation_turn' => 1]);
        dashboardCall($user, ['conversation_id' => 'group-chat']);
        dashboardCall(dashboardViewer(), ['conversation_id' => 'group-chat']);

        $this->actingAs($user);
        $dashboard = dashboardWithSections($user);

        expect(collect(dashboardSection($dashboard, 'conversations')->assertOk()->json('data'))->pluck('conversation_id')->all())->toBe(['chat/with-slash']);
        dashboardSection($dashboard, 'conversations/chat/with-slash')->assertOk();
        dashboardSection($dashboard, 'conversations/group-chat')->assertNotFound();
    });

    it('keeps a conversation\'s text back unless messages are on', function () {
        $user = dashboardViewer();
        dashboardCall($user, [
            'conversation_id' => 'chat',
            'conversation_turn' => 1,
            'model_type' => 'text',
            'endpoint' => '/v1/chat/completions',
            'request' => ['messages' => [['role' => 'system', 'content' => 'TOP-SECRET-SYSTEM'], ['role' => 'user', 'content' => 'CUSTOMER-TEXT']]],
            'response' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'REPLY-TEXT']]]],
        ]);

        $this->actingAs($user);
        $hidden = dashboardSection(dashboardWithSections($user), 'conversations/chat')->assertOk();
        $shown = dashboardSection(dashboardWithSections($user, ['name' => 'Messages', 'show_messages' => true]), 'conversations/chat')->assertOk();

        expect($hidden->getContent())->not->toContain('CUSTOMER-TEXT')->not->toContain('REPLY-TEXT')
            ->and($shown->json('turns.0.prompt'))->toBe('CUSTOMER-TEXT')
            ->and($shown->json('turns.0.reply'))->toBe('REPLY-TEXT')
            ->and($shown->getContent())->not->toContain('TOP-SECRET');
    });

    it('groups only the viewer\'s failures, with the message only when messages are on', function () {
        $user = dashboardViewer();
        $failure = ['status_code' => 500, 'error_type' => 'server_error', 'error_message' => 'INTERNAL-DETAIL', 'error_fingerprint' => 'abc'];
        dashboardCall($user, $failure);
        dashboardCall($user);
        dashboardCall(dashboardViewer(), [...$failure, 'error_fingerprint' => 'theirs']);
        dashboardCall(dashboardViewer(), $failure);

        $this->actingAs($user);
        $hidden = dashboardSection(dashboardWithSections($user), 'errors')->assertOk();
        $shown = dashboardSection(dashboardWithSections($user, ['name' => 'Messages', 'show_messages' => true]), 'errors')->assertOk();

        expect($hidden->json('request_count'))->toBe(2)
            ->and($hidden->json('failed_count'))->toBe(1)
            ->and(collect($hidden->json('groups'))->pluck('fingerprint')->all())->toBe(['abc'])
            ->and($hidden->json('groups.0.failure_count'))->toBe(1)
            ->and($hidden->getContent())->not->toContain('INTERNAL-DETAIL')->not->toContain('server_error')
            ->and($shown->json('groups.0.message'))->toBe('INTERNAL-DETAIL');
    });

    it('adds up only the viewer\'s successful spend', function () {
        $user = dashboardViewer();
        dashboardCall($user, ['total_cost_in_cents' => 10.0]);
        dashboardCall($user, ['total_cost_in_cents' => 99.0, 'status_code' => 500]);
        dashboardCall(dashboardViewer(), ['total_cost_in_cents' => 5000.0, 'model' => 'theirs-only']);

        $this->actingAs($user);
        $response = dashboardSection(dashboardWithSections($user), 'costs')->assertOk();

        // 10 cents: the failed call and the other user's are left out.
        expect($response->json('total_cost'))->toEqualWithDelta(10.0, 0.0001)
            ->and($response->json('total_requests'))->toBe(2)
            ->and($response->getContent())->not->toContain('theirs-only');
    });

    it('rejects all time on every page with a period', function (string $path) {
        $user = dashboardViewer();

        $this->actingAs($user);

        test()->getJson('/spectra/dashboards/'.dashboardWithSections($user)->id."/data/{$path}?period=all")->assertUnprocessable();
    })->with(['requests', 'traces', 'conversations', 'errors', 'costs']);

    it('saves the pages and message setting from the form and from code', function () {
        Gate::define('viewSpectra', fn ($user = null) => true);
        Gate::define('manageSpectra', fn ($user = null) => true);

        $id = $this->postJson('/spectra/api/dashboards', dashboardInput(['sections' => ['traces', 'errors'], 'show_messages' => true]))->assertCreated()->json('id');
        $fromCode = Spectra::dashboard()->create('Code', User::class, showCosts: true, sections: [DashboardSection::Requests, 'costs'], showMessages: true);

        expect(SpectraDashboard::query()->findOrFail($id)->enabledSections())->toBe(['traces', 'errors'])
            ->and(SpectraDashboard::query()->findOrFail($id)->show_messages)->toBeTrue()
            ->and($fromCode->enabledSections())->toBe(['requests', 'costs'])
            ->and($fromCode->show_messages)->toBeTrue();
        $this->postJson('/spectra/api/dashboards', dashboardInput(['sections' => ['raw_prompts']]))->assertUnprocessable();
        expect(fn () => Spectra::dashboard()->create('Code', User::class, sections: ['raw_prompts']))->toThrow(InvalidArgumentException::class, 'Unknown dashboard section');
    });
});
