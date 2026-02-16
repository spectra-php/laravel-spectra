# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

## v0.3.1 - 2026-02-16

### Bug Fixes

- Fixed `SpectraFake::assertTotalTokens()` using non-existent `totalTokens` property
- Fixed `streamingClass()` → `streamingHandler()` in custom-providers docs
- Fixed `ExportOtelTraceJob` → `ExportTrackedRequestJob` in OpenTelemetry docs
- Fixed `withResponseType('stream')` → `withOptions(['stream' => true])` in usage docs
- Fixed `aiBudgets()` → `aiBudget()` in troubleshooting docs
- Fixed `assertTracked` callback example in testing docs
- Removed non-existent config keys from budget configuration docs

### Non-Breaking Changes

- Removed unused query methods from `StatsAggregator`
- Fixed code style in `SpectraServiceProvider`

## v0.3.0 - 2026-02-16

### Breaking Changes

- Renamed `configureBudget()` to `configureAiBudget()` on the `HasAiBudget` trait
- Renamed BudgetBuilder methods for clarity: `dailyLimit()` → `dailyCostLimitInCents()`, `weeklyLimit()` → `weeklyCostLimitInCents()`, `monthlyLimit()` → `monthlyCostLimitInCents()`, `totalLimit()` → `totalCostLimitInCents()`, `warningThreshold()` → `warningThresholdPercentage()`, `criticalThreshold()` → `criticalThresholdPercentage()`
- Removed shorthand methods: `setDailyBudget()`, `setWeeklyBudget()`, `setMonthlyBudget()`, `setTotalBudget()` — use `configureAiBudget()` builder instead

### Non-Breaking Changes

- Fixed incorrect namespace references in docs (`GuzzleMiddleware`, `MediaPersister`)
- Fixed `withAITracking` signature in docs to use options array
- Removed non-existent env vars from troubleshooting docs
- Merged Providers and Dashboard doc pages into Models and Installation respectively

## v0.2.0 - 2026-02-15

- Update dependencies

## 0.1.0 - 2026-01-15

- Initial release
