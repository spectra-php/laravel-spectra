# Budgets

Budgets allow you to set spending and usage limits per user, team, or any Eloquent model in your application. When a limit is reached, Spectra can either block the request outright (hard limit) or fire a warning event while allowing the request to proceed (soft limit). This gives you fine-grained control over AI costs at the entity level, whether you need strict enforcement for billing compliance or gentle notifications for internal monitoring.

<a name="setup"></a>
## Setup

To enable budget tracking on a model, add the `HasAiUsage` trait. This is most commonly applied to your `User` model, but it can be added to any Eloquent model — teams, organizations, projects, or any other entity you want to budget:

```php
use Spectra\Concerns\HasAiUsage;

class User extends Authenticatable
{
    use HasAiUsage;
}
```

The trait provides methods for creating budgets, checking budget status, and querying remaining allowances. It uses a polymorphic relationship, so a single budget table supports any number of different model types.

<a name="defining-budgets"></a>
## Defining Budgets

Use the fluent builder returned by `configureBudget()` to define a budget. Chain the limits you need and call `save()` to persist:

```php
$user->configureBudget()
    ->dailyLimit(1000)          // $10/day
    ->monthlyLimit(20000)       // $200/month
    ->monthlyTokenLimit(5000000)
    ->warningThreshold(75)
    ->criticalThreshold(90)
    ->hardLimit()
    ->save();
```

All limits are optional — only set the ones you need. You can combine cost limits, token limits, and request-count limits in any combination.

<a name="budget-builder"></a>
### Budget Builder

The builder supports the following chainable methods:

**Cost limits** (in cents):

```php
$user->configureBudget()
    ->dailyLimit(1000)       // $10/day
    ->weeklyLimit(5000)      // $50/week
    ->monthlyLimit(20000)    // $200/month
    ->totalLimit(500000)     // $5,000 lifetime
    ->save();
```

**Token limits:**

```php
$user->configureBudget()
    ->dailyTokenLimit(500000)
    ->weeklyTokenLimit(2000000)
    ->monthlyTokenLimit(5000000)
    ->totalTokenLimit(50000000)
    ->save();
```

**Request count limits:**

```php
$user->configureBudget()
    ->dailyRequestLimit(200)
    ->weeklyRequestLimit(1000)
    ->monthlyRequestLimit(5000)
    ->save();
```

**Enforcement mode:**

```php
// Hard limit — blocks requests when exceeded (default)
$user->configureBudget()
    ->monthlyLimit(20000)
    ->hardLimit()
    ->save();

// Soft limit — allows requests but fires warning events
$user->configureBudget()
    ->monthlyLimit(20000)
    ->softLimit()
    ->save();
```

**Alert thresholds** (percentage 0–100):

```php
$user->configureBudget()
    ->monthlyLimit(20000)
    ->warningThreshold(75)     // Fire event at 75% usage
    ->criticalThreshold(90)    // Fire event at 90% usage
    ->save();
```

Setting either threshold to `0` disables it. The defaults are 80% (warning) and 95% (critical).

**Provider and model restrictions:**

```php
$user->configureBudget()
    ->monthlyLimit(20000)
    ->allowProviders(['openai', 'anthropic'])
    ->allowModels(['gpt-4o', 'claude-sonnet-4-20250514'])
    ->save();
```

When `allowProviders` or `allowModels` is set, any request to a provider or model not in the list is blocked regardless of budget status. Omitting these methods allows all providers and models.

**Named budgets:**

```php
$user->configureBudget()
    ->name('Production Budget')
    ->monthlyLimit(20000)
    ->save();
```

<a name="shorthand-methods"></a>
### Shorthand Methods

For simple budgets with a single limit, use the direct setter methods instead of the builder:

```php
$user->setDailyBudget(1000);     // $10/day
$user->setWeeklyBudget(5000);    // $50/week
$user->setMonthlyBudget(20000);  // $200/month
$user->setTotalBudget(500000);   // $5,000 lifetime
```

You can also pass an array directly:

```php
$user->setAiBudget([
    'daily_limit' => 1000,
    'monthly_limit' => 20000,
    'hard_limit' => true,
]);
```

<a name="checking-budget-status"></a>
## Checking Budget Status

You can programmatically check whether a user or entity has exceeded their budget, retrieve detailed status information, or query remaining allowances:

```php
// Has the user exceeded their budget?
if ($user->hasExceededBudget('openai', 'gpt-4o')) {
    // Handle exceeded budget
}

// Get detailed budget status
$status = $user->getBudgetStatus('openai', 'gpt-4o');
$status->allowed;          // bool — whether the next request would be allowed
$status->percentage;       // float — max usage percentage across all limits
$status->providerAllowed;  // bool — whether the provider is in the allowlist
$status->modelAllowed;     // bool — whether the model is in the allowlist
$status->usage;            // BudgetUsage DTO with current usage values
$status->limits;           // BudgetLimits DTO with configured limits

// Get remaining budget (in cents)
$remaining = $user->getRemainingBudget();
$remaining->daily;    // ?int — null if no daily limit set
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

For automatic enforcement on specific routes, Spectra provides the `spectra.budget` middleware. When a user's budget is exceeded and `hardLimit` is enabled, the middleware returns a `429 Too Many Requests` response. This prevents any AI requests from proceeding until the budget resets or is increased.

```php
// Enforce budget for a specific provider and model
Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
    ->post('/ai/chat', ChatController::class);

// Enforce budget using the default provider and model from config
Route::middleware(['auth', 'spectra.budget'])
    ->post('/ai/generate', GenerateController::class);
```

When `softLimit()` is configured, the middleware allows the request to proceed but still fires budget events, enabling you to implement soft warning behaviors such as sending notifications or displaying banners to the user.

<a name="events"></a>
## Events

Spectra dispatches events when budget thresholds are reached, giving you the opportunity to send notifications, trigger alerts, or take any other action in response to budget pressure.

### `BudgetThresholdReached`

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

### `BudgetExceeded`

Fired when a limit is fully exceeded:

```php
use Spectra\Events\BudgetExceeded;

Event::listen(BudgetExceeded::class, function ($event) {
    $event->budgetable;       // The model (User, Team, etc.)
    $event->budget;           // The SpectraBudget model
    $event->limitType;        // 'daily', 'weekly', 'monthly', etc.
    $event->currentUsage;     // Raw usage value
    $event->limit;            // The configured limit value
    $event->wasBlocked;       // true if hard_limit prevented the request

    $overage = $event->getOverageAmountInDollars(); // e.g. 4.25
    Log::warning("Budget exceeded by \${$overage}");
});
```

<a name="budget-limits-reference"></a>
## Budget Limits Reference

| Method | Limit | Type | Description |
| --- | --- | --- | --- |
| `dailyLimit()` | `daily_limit` | Cost (cents) | Maximum daily spend |
| `weeklyLimit()` | `weekly_limit` | Cost (cents) | Maximum weekly spend |
| `monthlyLimit()` | `monthly_limit` | Cost (cents) | Maximum monthly spend |
| `totalLimit()` | `total_limit` | Cost (cents) | Maximum lifetime spend |
| `dailyTokenLimit()` | `daily_token_limit` | Tokens | Maximum daily token usage (prompt + completion) |
| `weeklyTokenLimit()` | `weekly_token_limit` | Tokens | Maximum weekly token usage |
| `monthlyTokenLimit()` | `monthly_token_limit` | Tokens | Maximum monthly token usage |
| `totalTokenLimit()` | `total_token_limit` | Tokens | Maximum lifetime token usage |
| `dailyRequestLimit()` | `daily_request_limit` | Requests | Maximum daily request count |
| `weeklyRequestLimit()` | `weekly_request_limit` | Requests | Maximum weekly request count |
| `monthlyRequestLimit()` | `monthly_request_limit` | Requests | Maximum monthly request count |

<a name="configuration"></a>
## Configuration

Set global budget defaults in `config/spectra.php`. These values apply when individual budgets do not specify their own thresholds or behavior:

```php
'budget' => [
    'enabled' => true,
    'default_provider' => 'openai',
    'default_model' => 'gpt-4',
    'warning_threshold' => 80,
    'critical_threshold' => 95,
    'hard_limit' => true,
],
```
