# Budgets

Budgets let you set spending and usage limits per user, team, or any other Eloquent model in your application. When a limit is reached, Spectra either blocks the request (hard limit) or fires a warning event and lets the request go through (soft limit). Use a hard limit when you need strict enforcement, for example for billing compliance, and a soft limit when you only want to be notified, for example for internal monitoring.

<a name="setup"></a>
## Setup

To track budgets on a model, add the `HasAiUsage` trait. You will usually add it to your `User` model, but it works on any Eloquent model: teams, organizations, projects, or anything else you want to budget.

```php
use Spectra\Concerns\HasAiUsage;

class User extends Authenticatable
{
    use HasAiUsage;
}
```

The trait adds methods to create budgets, check their status, and query what is left. It uses a polymorphic relationship, so one budget table holds budgets for any number of model types.

<a name="defining-budgets"></a>
## Defining Budgets

Call `configureAiBudget()` to get a fluent builder. Chain the limits you need, then call `save()`:

```php
$user->configureAiBudget()
    ->dailyCostLimitInCents(1000)          // $10/day
    ->monthlyCostLimitInCents(20000)       // $200/month
    ->monthlyTokenLimit(5000000)
    ->warningThresholdPercentage(75)
    ->criticalThresholdPercentage(90)
    ->hardLimit()
    ->save();
```

Every limit is optional, so set only the ones you need. You can mix cost, token, and request-count limits however you like.

<a name="budget-builder"></a>
### Budget Builder

The builder has these chainable methods.

**Cost limits** (in cents):

```php
$user->configureAiBudget()
    ->dailyCostLimitInCents(1000)       // $10/day
    ->weeklyCostLimitInCents(5000)      // $50/week
    ->monthlyCostLimitInCents(20000)    // $200/month
    ->totalCostLimitInCents(500000)     // $5,000 lifetime
    ->save();
```

**Token limits:**

```php
$user->configureAiBudget()
    ->dailyTokenLimit(500000)
    ->weeklyTokenLimit(2000000)
    ->monthlyTokenLimit(5000000)
    ->totalTokenLimit(50000000)
    ->save();
```

**Request count limits:**

```php
$user->configureAiBudget()
    ->dailyRequestLimit(200)
    ->weeklyRequestLimit(1000)
    ->monthlyRequestLimit(5000)
    ->save();
```

**Enforcement mode:**

```php
// Hard limit: blocks requests when exceeded (default)
$user->configureAiBudget()
    ->monthlyCostLimitInCents(20000)
    ->hardLimit()
    ->save();

// Soft limit: allows requests but fires warning events
$user->configureAiBudget()
    ->monthlyCostLimitInCents(20000)
    ->softLimit()
    ->save();
```

**Alert thresholds** (a percentage from 0 to 100):

```php
$user->configureAiBudget()
    ->monthlyCostLimitInCents(20000)
    ->warningThresholdPercentage(75)     // Fire event at 75% usage
    ->criticalThresholdPercentage(90)    // Fire event at 90% usage
    ->save();
```

Setting either threshold to `0` disables it: a disabled warning never fires, and with the critical threshold disabled the warning fires anywhere from its percentage up to the limit. The defaults are 80% (warning) and 95% (critical).

**Provider and model restrictions:**

```php
$user->configureAiBudget()
    ->monthlyCostLimitInCents(20000)
    ->allowProviders(['openai', 'anthropic'])
    ->allowModels(['gpt-4o', 'claude-sonnet-4-6'])
    ->save();
```

When you set `allowProviders` or `allowModels`, any request to a provider or model that isn't in the list is blocked, whatever the budget status. Leave them out to allow every provider and model.

**Named budgets:**

```php
$user->configureAiBudget()
    ->name('Production Budget')
    ->monthlyCostLimitInCents(20000)
    ->save();
```

<a name="checking-budget-status"></a>
## Checking Budget Status

You can check in code whether a user or other model has exceeded its budget, get the detailed status, or see how much is left:

```php
// Has the user exceeded their budget?
if ($user->hasExceededBudget('openai', 'gpt-4o')) {
    // Handle exceeded budget
}

// Get detailed budget status
$status = $user->getBudgetStatus('openai', 'gpt-4o');
$status->allowed;          // bool: whether the next request would be allowed
$status->percentage;       // float: max usage percentage across all limits
$status->providerAllowed;  // bool: whether the provider is in the allowlist
$status->modelAllowed;     // bool: whether the model is in the allowlist
$status->usage;            // BudgetUsage DTO with current usage values
$status->limits;           // BudgetLimits DTO with configured limits

// Get remaining budget (in cents)
$remaining = $user->getRemainingBudget();
$remaining->daily;    // ?int: null if no daily limit set
$remaining->weekly;   // ?int
$remaining->monthly;  // ?int
$remaining->total;    // ?int
```

<a name="managing-budgets"></a>
### Managing Budgets

```php
// Temporarily disable (preserves limits for re-enabling later)
$user->disableAiBudget();

// Re-enable
$user->enableAiBudget();

// Permanently delete
$user->removeAiBudget();
```

<a name="middleware-enforcement"></a>
## Middleware Enforcement

To enforce budgets on specific routes, use the `spectra.budget` middleware. When a user's budget is exceeded and `hardLimit` is enabled, the middleware returns a `429 Too Many Requests` response. No AI requests go through until the budget resets or you raise it.

```php
// Enforce budget for a specific provider and model
Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
    ->post('/ai/chat', ChatController::class);

// Enforce budget using the default provider and model from config
Route::middleware(['auth', 'spectra.budget'])
    ->post('/ai/generate', GenerateController::class);
```

The middleware checks the provider and model named in its arguments. Without arguments, it uses `budget.default_provider` and `budget.default_model` from `config/spectra.php`. It never reads them from the request input, so a user can't choose which provider or model their budget is checked against.

If the check itself fails (for example, the database or cache is down), Spectra reports the error to your exception handler and lets the request through. Only a budget decision returns a 429.

With `softLimit()`, the middleware lets the request through but still fires budget events. You can use them for soft warnings, such as sending a notification or showing the user a banner.

::: info Enforcement is pre-flight
The middleware checks budgets **before** the request runs, using the spend recorded from *previous* requests. It can't know what the current request will cost. So the request that actually crosses a limit always completes. Right after its cost is recorded, Spectra detects the overage and dispatches a `BudgetExceeded` event (with `wasBlocked = false`). Every *later* request is then blocked by the hard limit. Listen for `BudgetExceeded` if you need to react to the crossing itself.
:::

<a name="events"></a>
## Events

Spectra dispatches events when a budget threshold is reached. Listen for them to send notifications, trigger alerts, or do anything else when a budget runs low.

<a name="alerts-fire-once"></a>
### Alerts Fire Once per Period {#alerts-fire-once}

The warning, critical, and exceeded alerts fire **once** per budget, limit, and period. For example, the monthly warning for a user fires once in October, and again in November if usage reaches it again. A daily limit can fire once per day, and a weekly limit once per week. If you change a limit, its alerts can fire again.

Spectra keeps the "already sent" mark in your application's default cache store, so it holds across PHP-FPM requests and queue workers. Use a shared cache store (such as Redis or the database) when you run more than one server. The `array` cache store is not shared between processes, so alerts can fire more than once with it.

One exception: a blocked request always dispatches `BudgetExceeded` (with `wasBlocked = true`), every time. See [`BudgetExceeded`](#budget-exceeded-event).

<a name="budget-threshold-reached-event"></a>
### `BudgetThresholdReached` {#budget-threshold-reached-event}

Fired when usage hits the warning or critical threshold:

```php
use Spectra\Events\BudgetThresholdReached;

Event::listen(BudgetThresholdReached::class, function ($event) {
    $event->budgetable;       // The model (User, Team, etc.)
    $event->budget;           // The SpectraBudget model
    $event->thresholdType;    // 'warning' or 'critical'
    $event->limitType;        // 'daily', 'weekly', 'monthly', etc.
    $event->percentageUsed;   // Current usage as a percentage
    $event->currentUsage;     // Raw usage value
    $event->limit;            // The configured limit value

    if ($event->isCritical()) {
        Notification::send($event->budgetable, new BudgetWarning($event));
    }
});
```

<a name="budget-exceeded-event"></a>
### `BudgetExceeded` {#budget-exceeded-event}

Fired in two cases:

- **A request is blocked** (`wasBlocked = true`). The middleware or `enforce()` dispatches it right before it stops the request, for every blocked request. This covers cost, token and request-count limits with `hardLimit()`, and a provider or model that is not in the budget's allowed list.
- **Recorded spend crosses a cost limit** (`wasBlocked = false`). Dispatched once per limit and period, after the request that crossed it is saved, with hard or soft limits.

`limitType` names the limit, and `currentUsage` and `limit` are in its unit:

| `limitType` | `currentUsage` and `limit` |
| --- | --- |
| `daily`, `weekly`, `monthly`, `total` | Cost in cents |
| `daily_tokens`, `weekly_tokens`, `monthly_tokens`, `total_tokens` | Tokens |
| `daily_requests`, `weekly_requests`, `monthly_requests` | Requests |
| `provider`, `model` | Both `0`: the provider or model is not allowed |

`getOverageAmountInDollars()` is meaningful for cost limits only.

```php
use Spectra\Events\BudgetExceeded;

Event::listen(BudgetExceeded::class, function ($event) {
    $event->budgetable;       // The model (User, Team, etc.)
    $event->budget;           // The SpectraBudget model
    $event->limitType;        // 'daily', 'daily_tokens', 'provider', etc.
    $event->currentUsage;     // Usage in the limit's unit
    $event->limit;            // The configured limit value
    $event->wasBlocked;       // true if the request was blocked

    if (! $event->wasBlocked) {
        $overage = $event->getOverageAmountInDollars(); // e.g. 4.25
        Log::warning("Budget exceeded by \${$overage}");
    }
});
```

<a name="budget-limits-reference"></a>
## Budget Limits Reference

| Method | Limit | Type | Description |
| --- | --- | --- | --- |
| `dailyCostLimitInCents()` | `daily_limit` | Cost (cents) | Maximum daily spend |
| `weeklyCostLimitInCents()` | `weekly_limit` | Cost (cents) | Maximum weekly spend |
| `monthlyCostLimitInCents()` | `monthly_limit` | Cost (cents) | Maximum monthly spend |
| `totalCostLimitInCents()` | `total_limit` | Cost (cents) | Maximum lifetime spend |
| `dailyTokenLimit()` | `daily_token_limit` | Tokens | Maximum daily token usage (prompt + completion) |
| `weeklyTokenLimit()` | `weekly_token_limit` | Tokens | Maximum weekly token usage |
| `monthlyTokenLimit()` | `monthly_token_limit` | Tokens | Maximum monthly token usage |
| `totalTokenLimit()` | `total_token_limit` | Tokens | Maximum lifetime token usage |
| `dailyRequestLimit()` | `daily_request_limit` | Requests | Maximum daily request count |
| `weeklyRequestLimit()` | `weekly_request_limit` | Requests | Maximum weekly request count |
| `monthlyRequestLimit()` | `monthly_request_limit` | Requests | Maximum monthly request count |

<a name="configuration"></a>
## Configuration

Set global budget defaults in `config/spectra.php`. They apply when a budget doesn't set its own thresholds or behavior:

```php
'budget' => [
    'enabled' => env('SPECTRA_BUDGET_ENABLED', true),
    'default_provider' => 'openai',
    'default_model' => 'gpt-5.2',
],
```

`default_provider` and `default_model` are what the `spectra.budget` middleware checks when the route doesn't name a provider or model. They only matter for budgets with allowed providers or models.
